<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Authentication;

use DateTimeImmutable;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Exception\MalformedApiKey;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Turns the token on a request into the key it belongs to.
 *
 * This is where a tenant context enters the system: everything downstream is
 * scoped by what this returns, and nothing downstream may widen it.
 *
 * Whether the lookup is cached is not this class's business — it asks a
 * repository, and the caching decorator sits behind the same port. What is its
 * business is that a revoked key is refused against the clock it was given,
 * which is what makes the revocation window testable without waiting.
 */
final readonly class ApiKeyAuthenticator
{
    public function __construct(
        private ApiKeyRepository $keys,
        private ClockInterface $clock,
        private int $usageRecordingIntervalSeconds,
    ) {}

    public function authenticate(#[SensitiveParameter] string $token): ApiKey
    {
        try {
            $secret = ApiKeySecret::fromToken($token);
        } catch (MalformedApiKey) {
            throw AuthenticationFailed::malformed();
        }

        $key = $this->keys->findByPrefix($secret->prefix());

        if ($key === null || ! $key->matches($secret)) {
            throw AuthenticationFailed::unknownKey();
        }

        $now = $this->clock->now();

        if ($key->isRevokedAt($now)) {
            throw AuthenticationFailed::revoked();
        }

        $this->recordUsage($key, $now);

        return $key;
    }

    /**
     * Last use is recorded at most once per interval.
     *
     * A write on every authenticated request would double the write load of
     * ingestion to maintain a column nobody reads to the second; what the
     * column is for is answering "is this key still in use?" before revoking
     * it, and a few minutes of resolution answers that.
     */
    private function recordUsage(ApiKey $key, DateTimeImmutable $now): void
    {
        $last = $key->lastUsedAt;

        if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < $this->usageRecordingIntervalSeconds) {
            return;
        }

        $this->keys->save($key->usedAt($now));
    }
}
