<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Application\Port\ApiRefused;
use Metered\Simulation\Application\Port\MeteredApi;
use SensitiveParameter;

/**
 * The API over HTTP, as a well-behaved client uses it: an Idempotency-Key on
 * every write, and a wait — as long as `Retry-After` asks, or a second — when
 * the answer is 429 (the key's rate limit) or 503 (ingestion backpressure).
 * A usage batch that fails in any way that is not a refusal of its content is
 * sent again as it was.
 * Anything else that is not a success stops the simulation with the problem
 * the API described.
 */
final readonly class HttpMeteredApi implements MeteredApi
{
    private const int ATTEMPTS = 30;

    private const int CONCURRENCY = 8;

    public function __construct(
        private Factory $http,
        private IdentifierGenerator $ids,
        private string $baseUrl,
        #[SensitiveParameter]
        private string $token,
    ) {}

    public function write(string $path, array $body): array
    {
        $key = $this->ids->generate()->value;

        for ($attempt = 1; ; ++$attempt) {
            $response = $this->request()->withHeaders(['Idempotency-Key' => $key])->post($path, $body);

            if ($this->shouldWait($response, $attempt)) {
                continue;
            }

            if (! $response->successful()) {
                throw ApiRefused::answered('POST', $path, $response->status(), $this->problem($response));
            }

            $answer = $response->json();

            return is_array($answer) ? $this->stringKeys($answer) : [];
        }
    }

    public function read(string $path, array $query = []): array
    {
        for ($attempt = 1; ; ++$attempt) {
            $response = $this->request()->get($path, $query);

            if ($this->shouldWait($response, $attempt)) {
                continue;
            }

            if (! $response->successful()) {
                throw ApiRefused::answered('GET', $path, $response->status(), self::problem($response));
            }

            $answer = $response->json();

            return is_array($answer) ? $this->stringKeys($answer) : [];
        }
    }

    public function ingest(array $batches): int
    {
        $accepted = 0;
        $pending = $batches;

        for ($attempt = 1; $pending !== []; ++$attempt) {
            $retry = [];
            $wait = 1;

            foreach (array_chunk($pending, self::CONCURRENCY) as $group) {
                $responses = $this->http->createPendingRequest()->pool(function (Pool $pool) use ($group): void {
                    foreach ($group as $i => $batch) {
                        $this->configure($pool->as((string) $i))->post('/usage/events', ['events' => $batch]);
                    }
                });

                foreach ($group as $i => $batch) {
                    $response = $responses[(string) $i] ?? null;

                    if ($response instanceof Response && $response->status() === 202) {
                        $count = $response->json('accepted');
                        $accepted += is_int($count) ? $count : 0;

                        continue;
                    }

                    // Throttled, shed, broken or not answered at all: the batch is
                    // sent again with the same event ids, which is what makes
                    // resending safe — ingestion deduplicates by them.
                    if ($attempt < self::ATTEMPTS && (! $response instanceof Response || in_array($response->status(), [429, 503], true) || $response->serverError())) {
                        $retry[] = $batch;
                        $wait = $response instanceof Response ? max($wait, $this->retryAfter($response)) : $wait;

                        continue;
                    }

                    throw ApiRefused::answered('POST', '/usage/events', $response instanceof Response ? $response->status() : 0, $response instanceof Response ? $this->problem($response) : 'no answer');
                }
            }

            // As long as the longest Retry-After asked: a key whose minute is
            // spent — by a seed that just ran on it — is refused until the
            // minute is over, and resending sooner only spends attempts.
            if ($retry !== []) {
                Sleep::for($wait)->seconds();
            }

            $pending = $retry;
        }

        return $accepted;
    }

    private function request(): PendingRequest
    {
        return $this->configure($this->http->createPendingRequest());
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->baseUrl(rtrim($this->baseUrl, '/') . '/api/v1')
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(30);
    }

    private function shouldWait(Response $response, int $attempt): bool
    {
        if (! in_array($response->status(), [429, 503], true) || $attempt >= self::ATTEMPTS) {
            return false;
        }

        Sleep::for($this->retryAfter($response))->seconds();

        return true;
    }

    /**
     * Seconds the answer asks to wait, at least one and at most a minute.
     */
    private function retryAfter(Response $response): int
    {
        return max(1, min((int) $response->header('Retry-After'), 60));
    }

    private function problem(Response $response): string
    {
        $detail = $response->json('detail');
        $errors = $response->json('errors');

        return trim((is_string($detail) ? $detail : $response->body()) . ' ' . (is_array($errors) ? json_encode($errors, JSON_UNESCAPED_SLASHES) : ''));
    }

    /**
     * @param  array<mixed>  $answer
     * @return array<string, mixed>
     */
    private function stringKeys(array $answer): array
    {
        $keyed = [];

        foreach ($answer as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
