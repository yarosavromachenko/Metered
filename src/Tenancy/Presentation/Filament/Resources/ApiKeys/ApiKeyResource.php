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
 * The keys of the project currently in scope.
 *
 * Scoped by both tenant columns rather than by project alone. The pair is what
 * ADR-0013 calls the tenant, and a screen that filtered by project id only
 * would still be correct today and wrong the first time an id arrives from
 * somewhere unexpected.
 *
 * There is nothing here that could show a secret, because there is nothing
 * stored that could be shown: the table holds a prefix and a hash.
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

        // No scope means nothing to show. The columns are NOT NULL, so a null
        // on either side matches no row — a filter quietly omitted would show
        // every tenant's keys instead.
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
