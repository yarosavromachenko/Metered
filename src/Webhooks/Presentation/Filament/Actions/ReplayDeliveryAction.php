<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Application\Command\ReplayDelivery;
use Metered\Webhooks\Application\Command\ReplayDeliveryHandler;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;

final class ReplayDeliveryAction
{
    public static function make(): Action
    {
        return Action::make('replay')
            ->label('Replay')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('Sent again from its first attempt, with the same body, within seconds.')
            ->visible(static fn(WebhookDelivery $record): bool => in_array($record->status, [DeliveryStatus::Dead, DeliveryStatus::Failed], true)
                && app(PanelScope::class)->may(Permission::OperateWebhooks))
            ->action(static fn(WebhookDelivery $record): null => self::run($record->id));
    }

    public static function run(string $deliveryId): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($deliveryId): string {
            $replayed = app(ReplayDeliveryHandler::class)->handle(new ReplayDelivery($tenant, Uuid::fromString($deliveryId), app(PanelScope::class)->actor()));

            return sprintf('The %s delivery goes out again within seconds.', $replayed->eventType->value);
        });
    }
}
