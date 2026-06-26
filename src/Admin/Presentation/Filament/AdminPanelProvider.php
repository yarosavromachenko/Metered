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
 * The panel shell: branding, authentication, the middleware stack, and where
 * to look for the screens.
 *
 * It owns nothing else. Resources belong to the modules whose data they show,
 * and are found by path rather than imported, so the shell keeps no
 * compile-time dependency on any module (ADR-0015, and the module boundaries
 * Deptrac enforces). A module that is deleted takes its screens with it.
 *
 * Tenant scope is not Filament's built-in tenancy. This system scopes by an
 * organization *and* a project, and Filament models one tenant; splitting the
 * two halves between a URL segment and a session would mean two places to
 * check before believing a query is scoped. Both halves live in the session
 * instead, validated against the signed-in person's memberships on every
 * request — which is what ADR-0013 asks for.
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

        // Sign-up exists only when demo mode names a page for it, so an
        // installation that is not a demo has no registration route at all
        // rather than a hidden one (ADR-0016).
        $registration = config('metered.admin.registration_page');

        return is_string($registration) && class_exists($registration)
            ? $panel->registration($registration)
            : $panel;
    }
}
