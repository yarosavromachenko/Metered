<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Metered\Billing\Application\Command\ChangeSubscriptionPlan;
use Metered\Billing\Application\Command\ChangeSubscriptionPlanHandler;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Billing\Infrastructure\Eloquent\Subscription;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;

final class ChangeSubscriptionPlanAction
{
    public static function make(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(static fn(Subscription $record): bool => $record->status === SubscriptionStatus::Active
                && app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                Select::make('version')
                    ->label('Plan version')
                    ->options(static fn(): array => PublishedVersions::options())
                    ->required()
                    ->helperText('Takes effect at the end of the current period. Currency and interval must stay the same.'),
            ])
            ->action(static fn(Subscription $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $subscriptionId, array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($subscriptionId, $data): string {
            $changed = app(ChangeSubscriptionPlanHandler::class)->handle(new ChangeSubscriptionPlan(
                $tenant,
                Uuid::fromString($subscriptionId),
                Form::id($data['version'] ?? null),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Plan changes on %s.', $changed->phases[count($changed->phases) - 1]->startsAt->format('Y-m-d H:i'));
        });
    }
}
