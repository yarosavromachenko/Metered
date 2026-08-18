<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Resources\Invoices;

use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Domain\Invoice\LineKind;
use Metered\Invoicing\Domain\Ledger\Direction;
use Metered\Invoicing\Infrastructure\Eloquent\Invoice;
use Metered\Invoicing\Infrastructure\Eloquent\InvoiceLine;
use Metered\Invoicing\Infrastructure\Eloquent\LedgerEntry;
use Metered\Invoicing\Infrastructure\Eloquent\LedgerTransaction;
use Metered\Invoicing\Presentation\Filament\Resources\Invoices\Pages\ListInvoices;
use Metered\Invoicing\Presentation\Filament\Resources\Invoices\Pages\ViewInvoice;
use Metered\Tenancy\Application\Contract\PanelScope;
use UnitEnum;

final class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    protected static UnitEnum|string|null $navigationGroup = 'Invoicing';

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
                TextColumn::make('number')
                    ->label('Number')
                    ->fontFamily('mono')
                    ->state(static fn(Invoice $record): string => $record->printedNumber() ?? 'draft')
                    ->sortable(),
                TextColumn::make('customer_ref')->label('Customer')->fontFamily('mono')->searchable()
                    ->description(static fn(Invoice $record): string => $record->customer_name),
                TextColumn::make('period')
                    ->state(static fn(Invoice $record): string => sprintf('%s – %s', $record->period_start->format('Y-m-d'), $record->period_end->format('Y-m-d'))),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn(InvoiceStatus $state): string => ucfirst($state->value))
                    ->color(static fn(InvoiceStatus $state): string => self::statusColor($state)),
                TextColumn::make('total_minor')
                    ->label('Total')
                    ->alignEnd()
                    ->state(static fn(Invoice $record): string => (string) $record->total())
                    ->sortable(),
                TextColumn::make('finalized_at')->label('Issued')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(
                    array_map(static fn(InvoiceStatus $s): string => $s->value, InvoiceStatus::cases()),
                    array_map(static fn(InvoiceStatus $s): string => ucfirst($s->value), InvoiceStatus::cases()),
                )),
            ])
            ->defaultSort('built_at', 'desc')
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('A subscription is invoiced one hour after each of its periods ends.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('number')->state(static fn(Invoice $record): string => $record->printedNumber() ?? 'draft')->fontFamily('mono'),
                    TextEntry::make('status')->badge()
                        ->formatStateUsing(static fn(InvoiceStatus $state): string => ucfirst($state->value))
                        ->color(static fn(InvoiceStatus $state): string => self::statusColor($state)),
                    TextEntry::make('bill_to')->label('Bill to')
                        ->state(static fn(Invoice $record): string => sprintf('%s (%s)', $record->customer_name, $record->customer_ref)),
                    TextEntry::make('total')->state(static fn(Invoice $record): string => (string) $record->total())->weight('bold'),
                    TextEntry::make('period')->label('Period (UTC)')->columnSpan(2)
                        ->state(static fn(Invoice $record): string => sprintf('%s – %s', $record->period_start->format('Y-m-d H:i'), $record->period_end->format('Y-m-d H:i'))),
                    TextEntry::make('finalized_at')->label('Issued')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('Y-m-d H:i')->placeholder('—'),
                ]),
            ]),
            Section::make('Lines')
                ->description('Each line with the working that priced it.')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('lines')->hiddenLabel()
                        ->state(static fn(Invoice $record): Collection => $record->orderedLines())
                        ->schema([
                            TextEntry::make('description')->hiddenLabel()->weight('bold')
                                ->badge(static fn(InvoiceLine $record): bool => $record->kind === LineKind::Late)
                                ->color(static fn(InvoiceLine $record): ?string => $record->kind === LineKind::Late ? 'warning' : null),
                            TextEntry::make('quantity')->placeholder('—'),
                            TextEntry::make('amount')->state(static fn(InvoiceLine $record): string => (string) $record->amount()),
                            TextEntry::make('covers')->label('Bills')
                                ->state(static fn(InvoiceLine $record): string => sprintf('%s – %s', $record->covers_start->format('Y-m-d'), $record->covers_end->format('Y-m-d'))),
                            TextEntry::make('calculation')->label('How it was computed')->listWithLineBreaks()->columnSpanFull()->color('gray'),
                        ])->columns(4),
                ]),
            Section::make('Credit note')
                ->columnSpanFull()
                ->visible(static fn(Invoice $record): bool => $record->creditNote !== null)
                ->schema([
                    TextEntry::make('creditNote.number')->label('Number')->fontFamily('mono')
                        ->state(static fn(Invoice $record): ?string => $record->creditNote?->printedNumber()),
                    TextEntry::make('creditNote.reason')->label('Reason'),
                    TextEntry::make('creditNote.issued_at')->label('Issued')->dateTime('Y-m-d H:i'),
                ])->columns(3),
            Section::make('Ledger')
                ->description('What this invoice booked. Debits equal credits in every transaction.')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('ledgerTransactions')->hiddenLabel()
                        ->state(static fn(Invoice $record): Collection => $record->ledgerTransactions->sortBy('occurred_at')->values())
                        ->schema([
                            TextEntry::make('posting')->hiddenLabel()->badge()
                                ->formatStateUsing(static fn(LedgerTransaction $record): string => $record->posting->value),
                            TextEntry::make('occurred_at')->hiddenLabel()->dateTime('Y-m-d H:i'),
                            TextEntry::make('entries')->hiddenLabel()->columnSpan(2)->listWithLineBreaks()
                                ->state(static fn(LedgerTransaction $record): array => $record->entries->sortBy('id')
                                    ->map(static fn(LedgerEntry $entry): string => sprintf(
                                        '%s %s %s',
                                        $entry->direction === Direction::Debit ? 'Dr' : 'Cr',
                                        str_replace('_', ' ', $entry->account->value),
                                        $entry->amount(),
                                    ))
                                    ->all()),
                        ])->columns(4),
                ]),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }

    private static function statusColor(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Draft => 'gray',
            InvoiceStatus::Finalized => 'warning',
            InvoiceStatus::Paid => 'success',
            InvoiceStatus::Void => 'danger',
        };
    }
}
