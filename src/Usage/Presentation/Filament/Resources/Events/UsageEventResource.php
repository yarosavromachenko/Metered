<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Events;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Usage\Infrastructure\Eloquent\UsageEvent;
use Metered\Usage\Presentation\Filament\Resources\Events\Pages\ListUsageEvents;
use UnitEnum;

/**
 * The usage explorer: the raw events, newest first.
 *
 * Deliberately read-only — there is no action on this screen and no route to
 * one. An event is a fact that already happened; correcting it is another
 * event or a credit note, never an edit.
 *
 * The default sort is `occurred_at` descending, which is also the leading
 * column of the partition key, so the first page of this screen reads one
 * partition rather than all of them.
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
                    // The gap between the two is the story a late-event
                    // investigation is about.
                    ->tooltip('When this reached us, as opposed to when it happened'),
            ])
            ->filters([
                // By the code rather than by a relationship: meters belong to
                // Billing, and this screen reads only its own module's tables.
                SelectFilter::make('meter_code')
                    ->label('Meter')
                    ->options(static fn(): array => UsageEventResource::meterCodes())
                    ->searchable(),
                Filter::make('recent')
                    ->label('Last hour only')
                    ->query(static fn(Builder $query): Builder => $query->where('occurred_at', '>=', now()->subHour())),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No events yet')
            ->emptyStateDescription('Events appear here seconds after they are accepted.');
    }

    /**
     * The codes this project has actually sent events under — which is a
     * better list to filter by than every meter ever defined.
     *
     * @return array<string, string>
     */
    public static function meterCodes(): array
    {
        $tenant = app(PanelScope::class)->tenant();

        $codes = DB::table('usage_events')
            ->where('project_id', $tenant?->projectId->value)
            ->distinct()
            ->orderBy('meter_code')
            ->pluck('meter_code')
            ->all();

        $options = [];

        foreach ($codes as $code) {
            if (is_string($code)) {
                $options[$code] = $code;
            }
        }

        return $options;
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
