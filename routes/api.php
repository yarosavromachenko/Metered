<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Endpoints arrive with the modules that own them: usage ingestion in M3,
| the billing catalog in M4, invoices in M5, webhooks in M6. Each module
| registers its own routes from its presentation layer.
|
*/

Route::prefix('v1')->group(function (): void {
    //
});
