<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Metered\Billing\Application\Command\CancelSubscription;
use Metered\Billing\Application\Command\CancelSubscriptionHandler;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Billing\Infrastructure\Eloquent\Subscription;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

final class CancelSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(static fn(Subscription $record): bool => $record->status !== SubscriptionStatus::Canceled
                && app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                Toggle::make('immediately')
                    ->label('Cancel now instead of at the end of the period'),
            ])
            ->action(static fn(Subscription $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $subscriptionId, array $data): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($subscriptionId, $data): string {
            $canceled = app(CancelSubscriptionHandler::class)->handle(new CancelSubscription(
                $tenant,
                Uuid::fromString($subscriptionId),
                ($data['immediately'] ?? false) === true,
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Subscription ends %s.', $canceled->endsAt?->format('Y-m-d H:i') ?? 'now');
        });
    }
}
