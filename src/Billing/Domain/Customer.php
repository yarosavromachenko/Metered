<?php

declare(strict_types=1);

namespace Metered\Billing\Domain;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Domain\Text\Name;

/**
 * Events name a customer by {@see CustomerReference} (the tenant's own id);
 * subscriptions, aggregates and invoices reference the UUID.
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
