<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Metered\Shared\Infrastructure\Laravel\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
];
