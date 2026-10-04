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
 * Resolves the request token to its key; the tenant context of everything
 * downstream comes from here. Caching is a repository decorator. Revocation
 * is checked against the injected clock.
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

        if (!$key instanceof ApiKey || ! $key->matches($secret)) {
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
     * Last use is written at most once per interval, not on every request.
     */
    private function recordUsage(ApiKey $key, DateTimeImmutable $now): void
    {
        $last = $key->lastUsedAt;

        if ($last instanceof DateTimeImmutable && $now->getTimestamp() - $last->getTimestamp() < $this->usageRecordingIntervalSeconds) {
            return;
        }

        $this->keys->recordUse($key, $now);
    }
}
