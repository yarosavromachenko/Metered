<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Metered\Billing\Application\Command\DraftPlanVersion;
use Metered\Billing\Application\Command\DraftPlanVersionHandler;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Infrastructure\Eloquent\Plan;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

final class DraftPlanVersionAction
{
    public static function make(): Action
    {
        return Action::make('draftVersion')
            ->label('New version')
            ->icon('heroicon-o-document-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                Select::make('interval')
                    ->options(['month' => 'Monthly', 'year' => 'Yearly'])
                    ->default('month')
                    ->required()
                    ->helperText('Priced in the project\'s currency. Add prices, then publish.'),
            ])
            ->action(static fn(Plan $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $planId, array $data): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($planId, $data): string {
            $version = app(DraftPlanVersionHandler::class)->handle(new DraftPlanVersion(
                $tenant,
                Uuid::fromString($planId),
                BillingInterval::tryFrom(Form::text($data['interval'] ?? null)) ?? BillingInterval::Month,
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Version %d drafted. Add its prices under Plan versions.', $version->number);
        });
    }
}
