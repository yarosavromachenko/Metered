<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Who may open the Horizon dashboard.
     *
     * The dashboard lists job payloads, which is to say customer data and, in a
     * badly written job, secrets. It is therefore closed by default and opened
     * only where the whole stack is already local to the person looking at it.
     *
     * Once organisations have members and roles (M2), this becomes a check for
     * the owner role instead of a check on the environment.
     */
    protected function gate(): void
    {
        Gate::define(
            'viewHorizon',
            fn(?Authenticatable $user = null): bool => $this->app->environment('local', 'demo'),
        );
    }
}
