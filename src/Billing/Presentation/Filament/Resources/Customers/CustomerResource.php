<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Customers;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Infrastructure\Eloquent\Customer;
use Metered\Billing\Presentation\Filament\Resources\Customers\Pages\ListCustomers;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

/**
 * The parties this project bills, under the references their events carry.
 */
final class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Customers';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

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
                TextColumn::make('reference')->searchable()->sortable()->fontFamily('mono'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Registered'),
            ])
            ->defaultSort('reference')
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Register a customer under the id your own system uses for them.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
        ];
    }
}
