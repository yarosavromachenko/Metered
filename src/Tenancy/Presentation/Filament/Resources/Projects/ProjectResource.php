<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\Projects;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Infrastructure\Eloquent\Project;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\Projects\Pages\ListProjects;

/**
 * Filtered by the organization in the panel scope (ADR-0015).
 */
final class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Projects';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        $query = parent::getEloquentQuery();

        // Without a scope, null matches no row (NOT NULL column).
        return $query->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('environment')
                    ->badge()
                    ->formatStateUsing(static fn(Environment $state): string => $state->value)
                    ->color(static fn(Environment $state): string => $state->isLive() ? 'success' : 'warning'),
                TextColumn::make('currency'),
                TextColumn::make('api_keys_count')
                    ->counts('apiKeys')
                    ->label('Keys'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Created'),
            ])
            ->defaultSort('created_at')
            ->emptyStateHeading('No projects yet');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
        ];
    }
}
