<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Billing\Application\Command\PublishPlanVersion;
use Metered\Billing\Application\Command\PublishPlanVersionHandler;
use Metered\Billing\Infrastructure\Eloquent\PlanVersion;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

final class PublishPlanVersionAction
{
    public static function make(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon('heroicon-o-lock-closed')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('A published version can be subscribed to and never changes again. A new price means a new version.')
            ->visible(static fn(PlanVersion $record): bool => $record->published_at === null
                && app(PanelScope::class)->may(Permission::ManageCatalog))
            ->action(static fn(PlanVersion $record): null => self::run($record->id));
    }

    public static function run(string $versionId): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($versionId): string {
            $version = app(PublishPlanVersionHandler::class)->handle(new PublishPlanVersion(
                $tenant,
                Uuid::fromString($versionId),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Version %d published.', $version->number);
        });
    }
}
