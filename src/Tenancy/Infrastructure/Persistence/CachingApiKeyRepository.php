<?php

declare(strict_types=1);

namespace Metered\Tenancy\Infrastructure\Persistence;

use Illuminate\Contracts\Cache\Repository as Cache;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeyRepository;

/**
 * Keeps the authentication lookup off the database on the hot path.
 *
 * Every ingestion request authenticates, and without this the first query of
 * every request would be the same one. Only findByPrefix is cached: it is the
 * lookup that runs per request, and it is keyed by something unique
 * platform-wide.
 *
 * The TTL is what bounds revocation. Writing through the repository drops the
 * entry immediately, so a key revoked in the panel stops working at once on
 * every node sharing the cache; the TTL is the guarantee that holds when that
 * invalidation is missed — a node with a local store, a cache flushed between
 * two writes, a revocation applied by a migration. That is the documented
 * promise: a revoked key stops working within the window, and never longer
 * (ADR-0017, docs/api.md).
 *
 * The cache key carries a version because what is stored is a serialized
 * domain object. Changing the shape of ApiKey while entries are live would
 * otherwise unserialize into the old shape; bumping the version retires them
 * instead.
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

        // After the write, not before: a reader racing this call must not be
        // able to repopulate the entry from the row as it was.
        $this->cache->forget(self::KEY_PREFIX . $key->prefix);
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        $cached = $this->cache->get(self::KEY_PREFIX . $prefix);

        if ($cached instanceof ApiKey) {
            return $cached;
        }

        $key = $this->keys->findByPrefix($prefix);

        if ($key === null) {
            // Misses are not cached. A miss is what a random or expired token
            // produces, and caching those would let anyone fill the store with
            // entries of their choosing.
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
