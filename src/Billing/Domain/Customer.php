<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * The party a tenant bills, as this system knows them.
 *
 * The identity that matters to a client is {@see CustomerReference} — the id
 * they already use — because that is what their instrumentation can put in an
 * event without looking anything up first. The UUID is ours, and it is what
 * subscriptions, aggregates and invoices point at, so that renaming a
 * reference in a later milestone cannot orphan a year of billing history.
 */
final readonly class Customer
{
    public const int NAME_LIMIT = 120;

    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public CustomerReference $reference,
        public string $name,
        public DateTimeImmutable $registeredAt,
    ) {}

    public static function register(
        Uuid $id,
        TenantContext $tenant,
        CustomerReference $reference,
        string $name,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $tenant, $reference, Name::of($name, 'customer', self::NAME_LIMIT), $at);
    }

    public function answersTo(CustomerReference $reference): bool
    {
        return $this->reference->equals($reference);
    }
}
