<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * The unit of tenant scoping, usually `live` or `test`. Its currency is set
 * once and used by everything beneath it (ADR-0007).
 */
final readonly class Project
{
    public const int NAME_LIMIT = 120;

    private function __construct(
        public Uuid $id,
        public Uuid $organizationId,
        public string $name,
        public Slug $slug,
        public Environment $environment,
        public string $currency,
        public DateTimeImmutable $createdAt,
    ) {}

    public static function open(
        Uuid $id,
        Uuid $organizationId,
        string $name,
        Slug $slug,
        Environment $environment,
        string $currency,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $id,
            $organizationId,
            Name::of($name, 'project', self::NAME_LIMIT),
            $slug,
            $environment,
            // Fails here, not at invoicing, if Money does not know the currency.
            Money::zero($currency)->currency(),
            $at,
        );
    }

    public function tenant(): TenantContext
    {
        return new TenantContext($this->organizationId, $this->id);
    }
}
