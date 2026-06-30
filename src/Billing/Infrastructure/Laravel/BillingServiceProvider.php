<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Laravel;

use Illuminate\Support\ServiceProvider;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Infrastructure\Catalog\DatabaseCustomerDirectory;
use Metered\Billing\Infrastructure\Catalog\DatabaseMeterCatalog;
use Metered\Billing\Infrastructure\Persistence\DatabaseCustomerRepository;
use Metered\Billing\Infrastructure\Persistence\DatabaseMeterRepository;

/**
 * Wires the catalog: the repositories Billing uses itself, and the two
 * contracts other modules resolve against.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MeterRepository::class, DatabaseMeterRepository::class);
        $this->app->singleton(CustomerRepository::class, DatabaseCustomerRepository::class);

        $this->app->singleton(MeterCatalog::class, DatabaseMeterCatalog::class);
        $this->app->singleton(CustomerDirectory::class, DatabaseCustomerDirectory::class);
    }
}
