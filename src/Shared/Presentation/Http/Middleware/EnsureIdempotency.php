<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Metered\Shared\Application\Idempotency\IdempotencyStore;
use Metered\Shared\Domain\Idempotency\ClaimStatus;
use Metered\Shared\Domain\Idempotency\StoredResponse;
use Metered\Shared\Presentation\Http\IdempotencyScope;
use Metered\Shared\Presentation\Http\Problem;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * IETF idempotency-key draft:
 *
 * | Situation                          | Answer                      |
 * |------------------------------------|-----------------------------|
 * | New key                            | execute, store the response |
 * | Same key, same request, finished   | replay the stored response  |
 * | Same key, first request still open | 409                         |
 * | Same key, different request        | 422                         |
 * | No key                             | 400                         |
 *
 * `Idempotent-Replayed` is Stripe's header, not part of the draft.
 */
final readonly class EnsureIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    public function __construct(
        private IdempotencyStore $store,
        private IdempotencyScope $scope,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || trim($key) === '') {
            return Problem::response(
                'idempotency-key-required',
                'Idempotency key required',
                400,
                sprintf('This endpoint requires an %s header so that retries are safe.', self::HEADER),
                Problem::instanceFor($request),
            );
        }

        $key = trim($key);
        $scope = $this->scope->forRequest($request);
        $fingerprint = $this->fingerprint($request);

        $claim = $this->store->claim($scope, $key, $fingerprint);

        return match ($claim->status) {
            ClaimStatus::FingerprintMismatch => Problem::response(
                'idempotency-key-reused',
                'Idempotency key reused',
                422,
                'This idempotency key was already used for a different request. Use a new key.',
                Problem::instanceFor($request),
            ),
            ClaimStatus::InProgress => Problem::response(
                'idempotency-key-in-progress',
                'Request already in progress',
                409,
                'An identical request is still being processed. Retry in a moment.',
                Problem::instanceFor($request),
            )->withHeaders(['Retry-After' => '1']),
            ClaimStatus::Replayed => $this->replay($claim->response),
            ClaimStatus::Claimed => $this->execute($request, $next, $scope, $key),
        };
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function execute(Request $request, Closure $next, string $scope, string $key): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Failed: release the key so a retry executes again.
            $this->store->release($scope, $key);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->store->release($scope, $key);

            return $response;
        }

        $this->store->complete($scope, $key, new StoredResponse(
            status: $response->getStatusCode(),
            headers: ['Content-Type' => (string) $response->headers->get('Content-Type', 'application/json')],
            body: (string) $response->getContent(),
        ));

        return $response;
    }

    private function replay(?StoredResponse $stored): Response
    {
        if (!$stored instanceof StoredResponse) {
            return new Response('', 500);
        }

        return new Response($stored->body, $stored->status, [
            ...$stored->headers,
            'Idempotent-Replayed' => 'true',
        ]);
    }

    /**
     * Method, path and body.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->path(),
            $request->getContent(),
        ]));
    }
}
