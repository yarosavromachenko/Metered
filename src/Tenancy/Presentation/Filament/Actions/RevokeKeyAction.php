<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Command\RevokeApiKey;
use Metered\Tenancy\Application\Command\RevokeApiKeyHandler;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Infrastructure\Eloquent\ApiKey;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * Hidden without ManageTenant; the handler checks again.
 */
final class RevokeKeyAction
{
    public static function make(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Requests using this key start failing within 30 seconds. This cannot be undone.')
            ->visible(static fn(ApiKey $record): bool => $record->revoked_at === null
                && app(PanelScope::class)->may(Permission::ManageTenant))
            ->action(static fn(ApiKey $record): null => self::run($record->getKey()));
    }

    public static function run(mixed $keyId): null
    {
        $tenant = app(PanelScope::class)->tenant();

        if ($tenant === null || ! is_string($keyId) || ! Uuid::isValid($keyId)) {
            return null;
        }

        try {
            app(RevokeApiKeyHandler::class)->handle(new RevokeApiKey(
                $tenant,
                Uuid::fromString($keyId),
                PanelActor::current(),
            ));
        } catch (PermissionDenied|TenantNotFound $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return null;
        }

        Notification::make()->title('Key revoked.')->success()->send();

        return null;
    }
}
