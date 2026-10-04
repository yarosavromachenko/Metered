<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\ApiKeys;

use BackedEnum;
use DateTimeInterface;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Tenancy\Infrastructure\Eloquent\ApiKey;
use Metered\Tenancy\Presentation\Filament\Actions\RevokeKeyAction;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\Pages\ListApiKeys;

/**
 * Filtered by organization and project (ADR-0013).
 */
final class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'API keys';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        // Without a scope, null matches no row (NOT NULL columns).
        return parent::getEloquentQuery()
            ->where('organization_id', $tenant?->organizationId->value)
            ->where('project_id', $tenant?->projectId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('prefix')
                    ->label('Key')
                    ->formatStateUsing(static fn(string $state): string => $state . '…')
                    ->color('gray')
                    ->copyable(),
                TextColumn::make('scopes')
                    ->badge()
                    ->separator(','),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn(?DateTimeInterface $state): string => $state instanceof DateTimeInterface ? 'Revoked' : 'Active')
                    ->color(static fn(?DateTimeInterface $state): string => $state instanceof DateTimeInterface ? 'danger' : 'success')
                    ->default(null),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('never'),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Issued'),
            ])
            ->recordActions([RevokeKeyAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No keys for this project');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
        ];
    }
}
