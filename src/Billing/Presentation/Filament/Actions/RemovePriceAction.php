<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Metered\Billing\Application\Command\RemovePrice;
use Metered\Billing\Application\Command\RemovePriceHandler;
use Metered\Billing\Infrastructure\Eloquent\PlanVersion;
use Metered\Billing\Infrastructure\Eloquent\Price;
use Metered\Billing\Presentation\Filament\PriceSummary;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

final class RemovePriceAction
{
    public static function make(): Action
    {
        return Action::make('removePrice')
            ->label('Remove price')
            ->icon('heroicon-o-minus-circle')
            ->color('gray')
            ->visible(static fn(PlanVersion $record): bool => $record->published_at === null
                && $record->prices->isNotEmpty()
                && app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema(static fn(PlanVersion $record): array => [
                Select::make('price')
                    ->options($record->prices->mapWithKeys(
                        static fn(Price $price): array => [$price->id => PriceSummary::of($price)],
                    )->all())
                    ->required(),
            ])
            ->action(static fn(PlanVersion $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $versionId, array $data): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($versionId, $data): string {
            app(RemovePriceHandler::class)->handle(new RemovePrice(
                $tenant,
                Uuid::fromString($versionId),
                Form::id($data['price'] ?? null),
                app(PanelScope::class)->actor(),
            ));

            return 'Price removed.';
        });
    }
}
