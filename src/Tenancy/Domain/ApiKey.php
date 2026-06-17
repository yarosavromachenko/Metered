<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Exception\InvalidApiKey;

/**
 * A machine's credential for one project.
 *
 * The key holds a prefix and a hash, never a secret: {@see ApiKeySecret}
 * exists for the instant between generating a token and handing it to whoever
 * asked for it, and the key that outlives it cannot reconstruct it.
 *
 * Revocation is a timestamp rather than a flag. "Was this key valid at the
 * moment that request arrived?" is a question an audit answers by looking at a
 * row, and a boolean throws away the only part of the answer that matters.
 *
 * @see Scope for what a key may do
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
     * Whether the presented token is this key's.
     *
     * hash_equals rather than `===`: the comparison runs on every
     * authenticated request against a value an attacker chooses, which is the
     * textbook setting for a timing oracle. Both operands are hex of the same
     * length, so there is nothing else for the timing to reveal.
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
        return $this->revokedAt !== null && $this->revokedAt <= $at;
    }

    public function revoke(DateTimeImmutable $at): self
    {
        // Revoking twice is not an error, and the second call must not move
        // the moment the key stopped being valid.
        return $this->revokedAt !== null ? $this : $this->with(revokedAt: $at);
    }

    public function usedAt(DateTimeImmutable $at): self
    {
        return $this->with(lastUsedAt: $at);
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

    private function with(?DateTimeImmutable $revokedAt = null, ?DateTimeImmutable $lastUsedAt = null): self
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
            $revokedAt ?? $this->revokedAt,
            $lastUsedAt ?? $this->lastUsedAt,
        );
    }
}
