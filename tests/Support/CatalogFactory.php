<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\Plan;
use Metered\Billing\Domain\Plan\PlanCode;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Pricing\FlatFee;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Meters and customers for tests that need something for usage to point at.
 *
 * Built on the repositories, like {@see TenantFactory}: a fixture that wrote
 * rows directly would keep passing after the mapping broke.
 */
final class CatalogFactory
{
    public static function meter(
        TenantContext $tenant,
        string $code = 'api.requests',
        Aggregation $aggregation = Aggregation::Sum,
        ?string $name = null,
    ): Meter {
        $meter = Meter::define(
            app(IdentifierGenerator::class)->generate(),
            $tenant,
            MeterCode::fromString($code),
            $name ?? ucfirst(str_replace(['.', '_', '-'], ' ', $code)),
            $aggregation,
            app(ClockInterface::class)->now(),
        );

        app(MeterRepository::class)->save($meter);

        return $meter;
    }

    public static function customer(
        TenantContext $tenant,
        string $reference = 'cus_4471',
        ?string $name = null,
    ): Customer {
        $customer = Customer::register(
            app(IdentifierGenerator::class)->generate(),
            $tenant,
            CustomerReference::fromString($reference),
            $name ?? 'Customer ' . $reference,
            app(ClockInterface::class)->now(),
        );

        app(CustomerRepository::class)->save($customer);

        return $customer;
    }

    public static function plan(TenantContext $tenant, string $code = 'pro', ?string $name = null): Plan
    {
        $plan = Plan::create(
            app(IdentifierGenerator::class)->generate(),
            $tenant,
            PlanCode::fromString($code),
            $name ?? ucfirst($code),
            app(ClockInterface::class)->now(),
        );

        app(PlanRepository::class)->save($plan);

        return $plan;
    }

    /**
     * A version of $plan holding $prices — by default a 49.00 flat fee — saved
     * as a draft, or published when $published.
     *
     * @param list<Price>|null $prices
     */
    public static function version(
        Plan $plan,
        ?array $prices = null,
        bool $published = true,
        string $currency = 'EUR',
        BillingInterval $interval = BillingInterval::Month,
    ): PlanVersion {
        $versions = app(PlanVersionRepository::class);

        $version = PlanVersion::draft(
            app(IdentifierGenerator::class)->generate(),
            $plan->tenant,
            $plan->id,
            $versions->nextNumber($plan->tenant, $plan->id),
            $currency,
            $interval,
            app(ClockInterface::class)->now(),
        );

        $prices ??= [Price::fixed(app(IdentifierGenerator::class)->generate(), FlatFee::of(Money::ofMinorUnits(4900, $currency)))];

        foreach ($prices as $price) {
            $version = $version->withPrice($price);
        }

        if ($published) {
            $version = $version->publish(app(ClockInterface::class)->now());
        }

        $versions->save($version);

        return $version;
    }

    public static function subscription(Customer $customer, PlanVersion $version, ?DateTimeImmutable $at = null): Subscription
    {
        $subscription = Subscription::start(
            app(IdentifierGenerator::class)->generate(),
            $customer->tenant,
            $customer->id,
            $version,
            $at ?? app(ClockInterface::class)->now(),
        );

        app(SubscriptionRepository::class)->save($subscription);

        return $subscription;
    }
}
