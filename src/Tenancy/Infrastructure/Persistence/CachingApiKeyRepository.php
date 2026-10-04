<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;

/**
 * Caches findByPrefix, which runs on every authenticated request. Saving a
 * key drops its entry; the TTL bounds revocation when that is missed
 * (ADR-0017, docs/api.md). The cache key is versioned because it stores a
 * serialized ApiKey: bump it when the class changes.
 */
final readonly class CachingApiKeyRepository implements ApiKeyRepository
{
    private const string KEY_PREFIX = 'metered:api_key:v1:';

    public function __construct(
        private ApiKeyRepository $keys,
        private Cache $cache,
        private int $ttlSeconds,
    ) {}

    public function save(ApiKey $key): void
    {
        $this->keys->save($key);

        // After the write. A read already in flight can still cache the old
        // row; the TTL bounds how long it lasts.
        $this->cache->forget(self::KEY_PREFIX . $key->prefix);
    }

    public function recordUse(ApiKey $key, DateTimeImmutable $at): void
    {
        $this->keys->recordUse($key, $at);

        // The cached copy holds the old last-use time and would write again
        // on every request until it expires.
        $this->cache->forget(self::KEY_PREFIX . $key->prefix);
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        $cached = $this->cache->get(self::KEY_PREFIX . $prefix);

        if ($cached instanceof ApiKey) {
            return $cached;
        }

        $key = $this->keys->findByPrefix($prefix);

        if (!$key instanceof ApiKey) {
            // Misses are not cached: random tokens would fill the cache.
            return null;
        }

        $this->cache->put(self::KEY_PREFIX . $prefix, $key, $this->ttlSeconds);

        return $key;
    }

    public function find(TenantContext $tenant, Uuid $id): ?ApiKey
    {
        return $this->keys->find($tenant, $id);
    }

    public function listFor(TenantContext $tenant): array
    {
        return $this->keys->listFor($tenant);
    }
}
