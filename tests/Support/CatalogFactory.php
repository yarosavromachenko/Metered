<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\CustomerReference;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Metering\Aggregation;
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
}
