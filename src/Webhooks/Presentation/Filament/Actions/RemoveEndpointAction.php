<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Application\Command\RemoveEndpoint;
use Metered\Webhooks\Application\Command\RemoveEndpointHandler;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;

final class RemoveEndpointAction
{
    public static function make(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The endpoint goes, and its deliveries and their log with it. Switching it off keeps both.')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::OperateWebhooks))
            ->action(static fn(WebhookEndpoint $record): null => self::run($record->id));
    }

    public static function run(string $endpointId): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($endpointId): string {
            app(RemoveEndpointHandler::class)->handle(new RemoveEndpoint($tenant, Uuid::fromString($endpointId), app(PanelScope::class)->actor()));

            return 'Endpoint removed.';
        });
    }
}
