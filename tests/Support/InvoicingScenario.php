<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Pricing\PerUnit;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Money\UnitPrice;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Project;
use Metered\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * One customer on one subscription, ready to be invoiced: a 29.00 flat fee and
 * api.requests at 0.01 each, anchored where the test says, with the clock
 * under the test's control and a billing operator to act.
 */
final readonly class InvoicingScenario
{
    private function __construct(
        public TenantContext $tenant,
        public MockClock $clock,
        public Meter $meter,
        public Customer $customer,
        public Subscription $subscription,
        public Actor $operator,
    ) {}

    public static function start(string $anchor = '2026-01-31 14:00:00', string $slug = 'acme'): self
    {
        $clock = new MockClock($anchor, 'UTC');
        app()->instance(ClockInterface::class, $clock);

        return self::in(TenantFactory::tenant($slug), $clock);
    }

    /**
     * The same, in a project that already exists — the one a panel test has
     * signed in to.
     */
    public static function in(Project $project, MockClock $clock, string $customerReference = 'cus_4471'): self
    {
        app()->instance(ClockInterface::class, $clock);

        $tenant = $project->tenant();
        $meter = CatalogFactory::meter($tenant, 'api.requests');
        $customer = CatalogFactory::customer($tenant, $customerReference);
        $ids = app(IdentifierGenerator::class);

        $version = CatalogFactory::version(CatalogFactory::plan($tenant), [
            Price::fixed($ids->generate(), FlatFee::of(Money::ofMinorUnits(2900, 'EUR'))),
            Price::metered($ids->generate(), PerUnit::at(UnitPrice::fromString('0.01', 'EUR')), $meter->id),
        ]);

        return new self(
            $tenant,
            $clock,
            $meter,
            $customer,
            CatalogFactory::subscription($customer, $version, $clock->now()),
            TenantFactory::member($project->organizationId, Role::BillingOperator, sprintf('billing-%s@metered.test', substr($project->organizationId->value, -12))),
        );
    }

    public function usage(string $bucket, string $quantity): void
    {
        UsageFactory::aggregate($this->customer, $this->meter, $bucket, $quantity);
    }

    public function at(string $instant): self
    {
        $this->clock->modify($instant);

        return $this;
    }
}
