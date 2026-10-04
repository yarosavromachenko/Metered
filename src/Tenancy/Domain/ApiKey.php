<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;
use Metered\Tenancy\Domain\Exception\InvalidApiKey;

/**
 * Stores the prefix and the hash; the plaintext lives only in
 * {@see ApiKeySecret}. Revocation is a timestamp, so validity at a given
 * moment can be checked.
 *
 * @see Scope
 */
final readonly class ApiKey
{
    public const int NAME_LIMIT = 80;

    /**
     * @param  list<Scope>  $scopes
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public string $name,
        public string $prefix,
        public string $secretHash,
        public Environment $environment,
        public array $scopes,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {}

    /**
     * @param  list<Scope>  $scopes
     */
    public static function issue(
        Uuid $id,
        TenantContext $tenant,
        string $name,
        ApiKeySecret $secret,
        array $scopes,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $id,
            $tenant,
            Name::of($name, 'API key', self::NAME_LIMIT),
            $secret->prefix(),
            $secret->hash(),
            $secret->environment(),
            self::atLeastOneScope($scopes),
            $at,
            null,
            null,
        );
    }

    /**
     * @param  list<Scope>  $scopes
     */
    public static function fromStorage(
        Uuid $id,
        TenantContext $tenant,
        string $name,
        string $prefix,
        string $secretHash,
        Environment $environment,
        array $scopes,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $revokedAt,
        ?DateTimeImmutable $lastUsedAt,
    ): self {
        return new self(
            $id,
            $tenant,
            $name,
            $prefix,
            $secretHash,
            $environment,
            self::atLeastOneScope($scopes),
            $createdAt,
            $revokedAt,
            $lastUsedAt,
        );
    }

    /**
     * Constant-time comparison (hash_equals).
     */
    public function matches(ApiKeySecret $presented): bool
    {
        return $this->environment === $presented->environment()
            && hash_equals($this->secretHash, $presented->hash());
    }

    public function allows(Scope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isRevokedAt(DateTimeImmutable $at): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable && $this->revokedAt <= $at;
    }

    public function revoke(DateTimeImmutable $at): self
    {
        // Idempotent: a second revoke keeps the first timestamp.
        return $this->revokedAt instanceof DateTimeImmutable ? $this : $this->with(revokedAt: $at);
    }

    /**
     * @param  list<Scope>  $scopes
     * @return list<Scope>
     */
    private static function atLeastOneScope(array $scopes): array
    {
        $unique = array_values(array_unique($scopes, SORT_REGULAR));

        if ($unique === []) {
            throw InvalidApiKey::withoutScopes();
        }

        return $unique;
    }

    private function with(DateTimeImmutable $revokedAt): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $this->name,
            $this->prefix,
            $this->secretHash,
            $this->environment,
            $this->scopes,
            $this->createdAt,
            $revokedAt,
            $this->lastUsedAt,
        );
    }
}
