<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Aggregates;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Usage\Infrastructure\Eloquent\UsageAggregate;
use Metered\Usage\Presentation\Filament\Resources\Aggregates\Pages\ListAggregates;
use Metered\Usage\Presentation\Filament\Resources\Events\UsageEventResource;
use UnitEnum;

/**
 * The hourly aggregates — what an invoice will actually be built from.
 *
 * Worth a screen of its own precisely because it is derived: when a tenant
 * disputes a number, this is where the conversation starts, and being able to
 * see the bucket next to the events that produced it turns "the invoice is
 * wrong" into a question with an answer.
 *
 * The query selects a synthetic key, because the real one is four columns and
 * a table needs one string per row.
 */
final class AggregateResource extends Resource
{
    protected static ?string $model = UsageAggregate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Aggregates';

    protected static UnitEnum|string|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        $query = parent::getEloquentQuery()
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);

        // Four columns identify a row and a table needs one string, so the
        // query manufactures one. Through the underlying query builder, where
        // a raw select is a declared method rather than a forwarded call.
        $query->getQuery()->selectRaw(
            "usage_aggregates.*, usage_aggregates.customer_id::text || ':' "
            . "|| usage_aggregates.meter_id::text || ':' "
            . '|| extract(epoch from usage_aggregates.bucket_start)::bigint::text AS id',
        );

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('bucket_start')->dateTime('Y-m-d H:00')->label('Hour')->sortable(),
                TextColumn::make('meter_code')->label('Meter')->fontFamily('mono')->searchable(),
                TextColumn::make('customer_ref')->label('Customer')->fontFamily('mono')->searchable(),
                TextColumn::make('quantity')->alignEnd(),
                TextColumn::make('event_count')->label('Events')->alignEnd(),
                TextColumn::make('updated_at')->dateTime('Y-m-d H:i:s')->label('Last folded')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('meter_code')
                    ->label('Meter')
                    ->options(static fn(): array => UsageEventResource::meterCodes())
                    ->searchable(),
            ])
            ->defaultSort('bucket_start', 'desc')
            ->emptyStateHeading('Nothing aggregated yet')
            ->emptyStateDescription('An aggregate appears as soon as the first event of its hour is written.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAggregates::route('/'),
        ];
    }
}
