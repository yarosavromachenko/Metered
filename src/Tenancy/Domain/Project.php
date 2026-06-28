<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * An isolated environment inside an organization, typically `live` and `test`.
 *
 * The project is the unit everything is scoped to: keys authenticate to one,
 * meters and customers belong to one, and every tenant-owned row carries its
 * id. It also declares the currency, once — every price, invoice and ledger
 * entry beneath it uses that currency, and no conversion exists anywhere in
 * the system (ADR-0007).
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
            // Validated by constructing an amount in it: the currency a
            // project stores must be one the money type can hold, and finding
            // that out at invoice time would be far too late.
            Money::zero($currency)->currency(),
            $at,
        );
    }

    /**
     * The scope to hand to a repository. Reading it off the project rather
     * than assembling it at the call site removes the chance of pairing a
     * project with the wrong organization.
     */
    public function tenant(): TenantContext
    {
        return new TenantContext($this->organizationId, $this->id);
    }
}
