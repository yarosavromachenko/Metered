<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Events\Dispatcher;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Tenancy\Application\Command\NotADemoOrganization;
use Metered\Tenancy\Application\Command\ResetDemoOrganization;
use Metered\Tenancy\Application\Command\ResetDemoOrganizationHandler;
use Metered\Tenancy\Application\Contract\DemoDataRequested;
use Metered\Tenancy\Infrastructure\Eloquent\Organization;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * "Reset demo data": for the owner of a demo tenant who has changed enough
 * to want the seeded one back (ADR-0016). Everything the tenant made goes —
 * invoices and ledger included — and the small profile is seeded again in
 * the background; people, projects and keys stay.
 *
 * Offered only in a demo organization, to someone who manages it.
 */
final class ResetDemoDataAction
{
    public static function make(): Action
    {
        return Action::make('reset-demo')
            ->label('Reset demo data')
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(static fn(): bool => self::offered())
            ->requiresConfirmation()
            ->modalDescription('Every meter, plan, customer, event, invoice and webhook in this organization is deleted and the demo data is seeded again. Members, projects and API keys stay.')
            ->action(static fn(): null => self::run());
    }

    public static function offered(): bool
    {
        $scope = app(PanelScope::class);
        $tenant = $scope->tenant();

        return $tenant !== null
            && $scope->may(Permission::ManageTenant)
            && Organization::query()->whereKey($tenant->organizationId->value)->value('demo') === true;
    }

    public static function run(): null
    {
        $tenant = app(PanelScope::class)->tenant();

        if ($tenant === null) {
            return null;
        }

        try {
            $issued = app(ResetDemoOrganizationHandler::class)->handle(new ResetDemoOrganization($tenant, PanelActor::current()));
        } catch (PermissionDenied|NotADemoOrganization $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return null;
        }

        app(Dispatcher::class)->dispatch(new DemoDataRequested($tenant->organizationId->value, $issued->secret->reveal()));

        Notification::make()
            ->title('Demo data is being rebuilt')
            ->body('Everything was cleared; the seeded data arrives over the next few seconds.')
            ->success()
            ->send();

        return null;
    }
}
