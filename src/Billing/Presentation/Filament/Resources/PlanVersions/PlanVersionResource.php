<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\PlanVersions;

use BackedEnum;
use DateTimeInterface;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Infrastructure\Eloquent\PlanVersion;
use Metered\Billing\Infrastructure\Eloquent\Price;
use Metered\Billing\Presentation\Filament\Actions\AddPriceAction;
use Metered\Billing\Presentation\Filament\Actions\PublishPlanVersionAction;
use Metered\Billing\Presentation\Filament\Actions\RemovePriceAction;
use Metered\Billing\Presentation\Filament\PriceSummary;
use Metered\Billing\Presentation\Filament\Resources\PlanVersions\Pages\ListPlanVersions;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

/**
 * Every version of every plan, with its prices spelled out. Drafts carry the
 * actions that shape them; a published version carries none, because it can
 * no longer change.
 */
final class PlanVersionResource extends Resource
{
    protected static ?string $model = PlanVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Plan versions';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->with(['plan', 'prices.meter'])
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plan.code')->label('Plan')->fontFamily('mono')->searchable(),
                TextColumn::make('number')->label('Version')->formatStateUsing(static fn(int $state): string => 'v' . $state),
                TextColumn::make('interval')->formatStateUsing(static fn(BillingInterval $state): string => $state->value)->color('gray'),
                TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn(?DateTimeInterface $state): string => $state instanceof DateTimeInterface ? 'Published' : 'Draft')
                    ->color(static fn(?DateTimeInterface $state): string => $state instanceof DateTimeInterface ? 'success' : 'warning')
                    ->default(null),
                TextColumn::make('prices')
                    ->label('Prices')
                    ->state(static fn(PlanVersion $record): array => $record->prices
                        ->sortBy('position')
                        ->map(static fn(Price $price): string => PriceSummary::of($price))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->placeholder('No prices yet'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Drafted'),
            ])
            ->filters([
                SelectFilter::make('plan_id')
                    ->label('Plan')
                    ->options(static fn(): array => self::plans()),
            ])
            ->recordActions([AddPriceAction::make(), RemovePriceAction::make(), PublishPlanVersionAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No versions yet')
            ->emptyStateDescription('Draft one from a plan, add its prices, then publish it.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListPlanVersions::route('/')];
    }

    /**
     * @return array<string, string>
     */
    private static function plans(): array
    {
        $tenant = app(PanelScope::class)->tenant();
        $options = [];

        foreach ($tenant instanceof TenantContext ? app(PlanRepository::class)->listFor($tenant) : [] as $plan) {
            $options[$plan->id->value] = $plan->code->value;
        }

        return $options;
    }
}
