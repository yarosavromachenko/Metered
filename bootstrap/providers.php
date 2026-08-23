<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Metered\Shared\Infrastructure\Laravel\SharedServiceProvider::class,
    Metered\Tenancy\Infrastructure\Laravel\TenancyServiceProvider::class,
    Metered\Usage\Infrastructure\Laravel\UsageServiceProvider::class,
    Metered\Billing\Infrastructure\Laravel\BillingServiceProvider::class,
    Metered\Invoicing\Infrastructure\Laravel\InvoicingServiceProvider::class,
    Metered\Webhooks\Infrastructure\Laravel\WebhooksServiceProvider::class,
    Metered\Admin\Presentation\Filament\AdminPanelProvider::class,
];
