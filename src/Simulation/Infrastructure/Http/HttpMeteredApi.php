<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Simulation\Application\Port\ApiRefused;
use Metered\Simulation\Application\Port\MeteredApi;
use SensitiveParameter;

/**
 * The API over HTTP, as a well-behaved client uses it: an Idempotency-Key on
 * every write, and a wait — as long as `Retry-After` asks, or a second — when
 * the answer is 429 (the key's rate limit) or 503 (ingestion backpressure).
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

    public function ingest(array $batches): int
    {
        $accepted = 0;
        $pending = $batches;

        for ($attempt = 1; $pending !== []; ++$attempt) {
            $retry = [];

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

                    if ($response instanceof Response && in_array($response->status(), [429, 503], true) && $attempt < self::ATTEMPTS) {
                        $retry[] = $batch;

                        continue;
                    }

                    throw ApiRefused::answered('POST', '/usage/events', $response instanceof Response ? $response->status() : 0, $response instanceof Response ? $this->problem($response) : 'no answer');
                }
            }

            if ($retry !== []) {
                usleep(1_000_000);
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

        $seconds = (int) $response->header('Retry-After');
        usleep(max(1, min($seconds, 60)) * 1_000_000);

        return true;
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
