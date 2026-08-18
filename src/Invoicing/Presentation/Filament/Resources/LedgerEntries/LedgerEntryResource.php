<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Resources\LedgerEntries;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Direction;
use Metered\Invoicing\Infrastructure\Eloquent\LedgerEntry;
use Metered\Invoicing\Presentation\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

/**
 * The journal, one row per entry, newest first. Read-only by nature: the
 * schema refuses to change a row, and nothing here tries.
 */
final class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Ledger';

    protected static UnitEnum|string|null $navigationGroup = 'Invoicing';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->with('transaction.invoice')
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('transaction.invoice.customer_ref')->label('Customer')->fontFamily('mono'),
                TextColumn::make('account')
                    ->formatStateUsing(static fn(Account $state): string => ucfirst(str_replace('_', ' ', $state->value))),
                TextColumn::make('debit')->alignEnd()
                    ->state(static fn(LedgerEntry $record): ?string => $record->direction === Direction::Debit ? (string) $record->amount() : null)
                    ->placeholder(''),
                TextColumn::make('credit')->alignEnd()
                    ->state(static fn(LedgerEntry $record): ?string => $record->direction === Direction::Credit ? (string) $record->amount() : null)
                    ->placeholder(''),
                TextColumn::make('transaction.posting')->label('Posting')->badge()
                    ->state(static fn(LedgerEntry $record): string => $record->transaction->posting->value),
                TextColumn::make('invoice')->label('Invoice')->fontFamily('mono')
                    ->state(static fn(LedgerEntry $record): string => $record->transaction->invoice->printedNumber() ?? '—'),
            ])
            ->filters([
                SelectFilter::make('account')->options(array_combine(
                    array_map(static fn(Account $a): string => $a->value, Account::cases()),
                    array_map(static fn(Account $a): string => ucfirst(str_replace('_', ' ', $a->value)), Account::cases()),
                )),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing booked yet')
            ->emptyStateDescription('A finalized invoice books what the customer owes; a payment, what they paid.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListLedgerEntries::route('/')];
    }
}
