<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Application\Command\RotateEndpointSecret;
use Metered\Webhooks\Application\Command\RotateEndpointSecretHandler;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;

final class RotateSecretAction
{
    public static function make(): Action
    {
        return Action::make('rotate')
            ->label('Rotate secret')
            ->icon('heroicon-o-key')
            ->requiresConfirmation()
            ->modalDescription('A new secret signs every delivery from now on. The old one keeps signing alongside it for a day, so the receiver can switch when it is ready.')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::OperateWebhooks))
            ->action(static fn(WebhookEndpoint $record): null => self::run($record->id));
    }

    public static function run(string $endpointId): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($endpointId): string {
            $rotated = app(RotateEndpointSecretHandler::class)->handle(new RotateEndpointSecret(
                $tenant,
                Uuid::fromString($endpointId),
                app(PanelScope::class)->actor(),
            ));

            SecretNotice::show($rotated->secret);

            return 'Secret rotated.';
        });
    }
}
