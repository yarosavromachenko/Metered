<?php

declare(strict_types=1);

namespace Metered\Admin\Presentation\Filament;

use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel shell: branding, auth, middleware. Resources are discovered by path
 * in the modules, so the shell imports none of them (ADR-0015). Filament's
 * tenancy is not used: the scope is organization plus project, kept in the
 * session and checked against memberships (ADR-0013).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Metered')
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->defaultThemeMode(ThemeMode::System)
            ->discoverResources(
                in: base_path('src/Tenancy/Presentation/Filament/Resources'),
                for: 'Metered\\Tenancy\\Presentation\\Filament\\Resources',
            )
            ->discoverResources(
                in: base_path('src/Billing/Presentation/Filament/Resources'),
                for: 'Metered\\Billing\\Presentation\\Filament\\Resources',
            )
            ->discoverResources(
                in: base_path('src/Usage/Presentation/Filament/Resources'),
                for: 'Metered\\Usage\\Presentation\\Filament\\Resources',
            )
            ->discoverResources(
                in: base_path('src/Invoicing/Presentation/Filament/Resources'),
                for: 'Metered\\Invoicing\\Presentation\\Filament\\Resources',
            )
            ->discoverResources(
                in: base_path('src/Webhooks/Presentation/Filament/Resources'),
                for: 'Metered\\Webhooks\\Presentation\\Filament\\Resources',
            )
            ->discoverWidgets(
                in: base_path('src/Usage/Presentation/Filament/Widgets'),
                for: 'Metered\\Usage\\Presentation\\Filament\\Widgets',
            )
            ->pages([
                Dashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Outside demo mode there is no registration route (ADR-0016).
        $registration = config('metered.admin.registration_page');

        return is_string($registration) && class_exists($registration)
            ? $panel->registration($registration)
            : $panel;
    }
}
