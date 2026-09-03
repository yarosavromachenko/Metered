<?php

declare(strict_types=1);

use Metered\Simulation\Infrastructure\Laravel\SimulationServiceProvider;

// The simulation is dev tooling and is deleted from the production image, so
// it is listed only where its code exists.
return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Metered\Shared\Infrastructure\Laravel\SharedServiceProvider::class,
    Metered\Tenancy\Infrastructure\Laravel\TenancyServiceProvider::class,
    Metered\Usage\Infrastructure\Laravel\UsageServiceProvider::class,
    Metered\Billing\Infrastructure\Laravel\BillingServiceProvider::class,
    Metered\Invoicing\Infrastructure\Laravel\InvoicingServiceProvider::class,
    Metered\Webhooks\Infrastructure\Laravel\WebhooksServiceProvider::class,
    Metered\Admin\Presentation\Filament\AdminPanelProvider::class,
    class_exists(SimulationServiceProvider::class) ? SimulationServiceProvider::class : null,
]));
