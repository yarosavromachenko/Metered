<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    Metered\Shared\Infrastructure\Laravel\SharedServiceProvider::class,
    Metered\Tenancy\Infrastructure\Laravel\TenancyServiceProvider::class,
    Metered\Admin\Presentation\Filament\AdminPanelProvider::class,
];
