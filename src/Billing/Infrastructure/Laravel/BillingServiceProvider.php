<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Infrastructure\Catalog\CatalogSubscriptionBilling;
use Metered\Billing\Infrastructure\Catalog\DatabaseCustomerDirectory;
use Metered\Billing\Infrastructure\Catalog\DatabaseMeterCatalog;
use Metered\Billing\Infrastructure\Persistence\DatabaseBillingPurger;
use Metered\Billing\Infrastructure\Persistence\DatabaseCustomerRepository;
use Metered\Billing\Infrastructure\Persistence\DatabaseMeterRepository;
use Metered\Billing\Infrastructure\Persistence\DatabasePlanRepository;
use Metered\Billing\Infrastructure\Persistence\DatabasePlanVersionRepository;
use Metered\Billing\Infrastructure\Persistence\DatabaseSubscriptionRepository;
use Metered\Billing\Presentation\Http\CancelSubscriptionController;
use Metered\Billing\Presentation\Http\ChangeSubscriptionPlanController;
use Metered\Billing\Presentation\Http\CreatePlanController;
use Metered\Billing\Presentation\Http\CreatePlanVersionController;
use Metered\Billing\Presentation\Http\DefineMeterController;
use Metered\Billing\Presentation\Http\ListCustomersController;
use Metered\Billing\Presentation\Http\ListMetersController;
use Metered\Billing\Presentation\Http\ListPlansController;
use Metered\Billing\Presentation\Http\RegisterCustomerController;
use Metered\Billing\Presentation\Http\StartSubscriptionController;
use Metered\Tenancy\Application\Contract\TenantDataPurger;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MeterRepository::class, DatabaseMeterRepository::class);
        $this->app->singleton(CustomerRepository::class, DatabaseCustomerRepository::class);
        $this->app->singleton(PlanRepository::class, DatabasePlanRepository::class);
        $this->app->singleton(PlanVersionRepository::class, DatabasePlanVersionRepository::class);
        $this->app->singleton(SubscriptionRepository::class, DatabaseSubscriptionRepository::class);

        $this->app->singleton(MeterCatalog::class, DatabaseMeterCatalog::class);
        $this->app->singleton(CustomerDirectory::class, DatabaseCustomerDirectory::class);
        $this->app->singleton(SubscriptionBilling::class, CatalogSubscriptionBilling::class);

        $this->app->tag([DatabaseBillingPurger::class], TenantDataPurger::TAG);
    }

    public function boot(): void
    {
        // Admin scope; every write requires an Idempotency-Key.
        Route::middleware(['api', 'api-key:admin', 'throttle-api-key'])
            ->prefix('api/v1')
            ->group(static function (): void {
                Route::get('meters', ListMetersController::class)->name('billing.meters.list');
                Route::post('meters', DefineMeterController::class)->middleware('idempotent')->name('billing.meters.define');
                Route::get('customers', ListCustomersController::class)->name('billing.customers.list');
                Route::post('customers', RegisterCustomerController::class)->middleware('idempotent')->name('billing.customers.register');
                Route::get('plans', ListPlansController::class)->name('billing.plans.list');
                Route::post('plans', CreatePlanController::class)->middleware('idempotent')->name('billing.plans.create');
                Route::post('plans/{plan}/versions', CreatePlanVersionController::class)->middleware('idempotent')->name('billing.plans.versions.create');
                Route::post('subscriptions', StartSubscriptionController::class)->middleware('idempotent')->name('billing.subscriptions.start');
                Route::post('subscriptions/{subscription}/change-plan', ChangeSubscriptionPlanController::class)->middleware('idempotent')
                    ->name('billing.subscriptions.change-plan');
                Route::post('subscriptions/{subscription}/cancel', CancelSubscriptionController::class)->middleware('idempotent')
                    ->name('billing.subscriptions.cancel');
            });
    }
}
