<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Plans;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Infrastructure\Eloquent\Plan;
use Metered\Billing\Presentation\Filament\Actions\DraftPlanVersionAction;
use Metered\Billing\Presentation\Filament\Resources\Plans\Pages\ListPlans;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

final class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Plans';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->withCount('versions')
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('versions_count')->label('Versions'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Created'),
            ])
            ->recordActions([DraftPlanVersionAction::make()])
            ->defaultSort('code')
            ->emptyStateHeading('No plans yet')
            ->emptyStateDescription('A plan is what customers subscribe to. Its prices live in versions.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListPlans::route('/')];
    }
}
