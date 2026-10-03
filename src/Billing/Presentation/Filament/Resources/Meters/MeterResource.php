<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Meters;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Infrastructure\Eloquent\Meter;
use Metered\Billing\Presentation\Filament\Resources\Meters\Pages\ListMeters;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

/**
 * Filtered by the project in the panel scope; no scope matches nothing.
 */
final class MeterResource extends Resource
{
    protected static ?string $model = Meter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Meters';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

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
                TextColumn::make('code')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('aggregation')
                    ->badge()
                    ->formatStateUsing(static fn(Aggregation $state): string => $state->label())
                    ->color('gray'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Defined'),
            ])
            ->defaultSort('code')
            ->emptyStateHeading('No meters yet')
            ->emptyStateDescription('A meter is what usage events name. Define one before sending events.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMeters::route('/'),
        ];
    }
}
