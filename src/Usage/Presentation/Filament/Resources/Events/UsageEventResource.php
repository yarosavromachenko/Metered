<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Events;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Usage\Infrastructure\Eloquent\UsageEvent;
use Metered\Usage\Presentation\Filament\Resources\Events\Pages\ListUsageEvents;
use UnitEnum;

/**
 * Read-only usage explorer. Sorted by `occurred_at` desc, served by the
 * recent-events index; simple pagination avoids a `count(*)`
 * (docs/query-plans.md).
 */
final class UsageEventResource extends Resource
{
    protected static ?string $model = UsageEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Events';

    protected static UnitEnum|string|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->dateTime('Y-m-d H:i:s')->label('Occurred')->sortable(),
                TextColumn::make('meter_code')->label('Meter')->fontFamily('mono')->searchable(),
                TextColumn::make('customer_ref')->label('Customer')->fontFamily('mono')->searchable(),
                TextColumn::make('quantity')->alignEnd(),
                TextColumn::make('event_id')->label('Event id')->fontFamily('mono')->searchable()->toggleable(),
                TextColumn::make('received_at')
                    ->dateTime('Y-m-d H:i:s')
                    ->label('Received')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('When this reached us, as opposed to when it happened'),
            ])
            ->filters([
                // By code: meters belong to Billing.
                SelectFilter::make('meter_code')
                    ->label('Meter')
                    ->options(static fn(): array => UsageEventResource::meterCodes())
                    ->searchable(),
                Filter::make('recent')
                    ->label('Last hour only')
                    ->query(static fn(Builder $query): Builder => $query->where('occurred_at', '>=', now()->subHour())),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->paginationMode(PaginationMode::Simple)
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Events appear here seconds after they are accepted.');
    }

    /**
     * From the catalog, not a `distinct` over the events.
     *
     * @return array<string, string>
     */
    public static function meterCodes(): array
    {
        $tenant = app(PanelScope::class)->tenant();

        if ($tenant === null) {
            return [];
        }

        $codes = app(MeterCatalog::class)->codes($tenant);

        return array_combine($codes, $codes);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsageEvents::route('/'),
        ];
    }
}
