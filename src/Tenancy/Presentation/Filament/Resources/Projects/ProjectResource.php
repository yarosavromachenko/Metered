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
 * The organization's projects.
 *
 * Reads its own module's Eloquent model directly, which ADR-0015 allows on the
 * query side. The one rule that matters here is the query itself: it is scoped
 * to the organization in the current panel scope, and a scope that resolves to
 * nothing yields a query that matches nothing rather than a query without a
 * filter.
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

        // No scope means nothing to show. A query with the filter left off
        // would show every tenant's projects, so the empty case is spelled
        // out: the column is NOT NULL, so this matches no row at all.
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
