<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Authorization\PermissionDenied;
use Metered\Tenancy\Application\Command\RevokeApiKey;
use Metered\Tenancy\Application\Command\RevokeApiKeyHandler;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Infrastructure\Eloquent\ApiKey;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * Revoking a key from the panel.
 *
 * Hidden from anyone who may not manage the tenant, and refused again by the
 * handler — the button is a courtesy, the check is the control. The record is
 * resolved by the table's scoped query, so a key from another tenant never
 * reaches this code; if one somehow did, the handler's scoped lookup would not
 * find it either.
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
