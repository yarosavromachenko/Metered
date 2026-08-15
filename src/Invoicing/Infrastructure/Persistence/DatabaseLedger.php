<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Invoicing\Domain\Ledger\LedgerEntry;
use Metered\Invoicing\Domain\Ledger\LedgerTransaction;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;

final readonly class DatabaseLedger implements Ledger
{
    public function __construct(private DatabaseManager $db) {}

    public function record(LedgerTransaction $transaction): void
    {
        $connection = $this->db->connection();
        $currency = $transaction->entries[0]->amount->currency();
        $occurredAt = $transaction->occurredAt->format(DatabaseInvoiceRepository::INSTANT);

        $connection->transaction(static function () use ($connection, $transaction, $currency, $occurredAt): void {
            $connection->table('ledger_transactions')->insert([
                'id' => $transaction->id->value,
                'organization_id' => $transaction->tenant->organizationId->value,
                'project_id' => $transaction->tenant->projectId->value,
                'customer_id' => $transaction->customerId->value,
                'invoice_id' => $transaction->invoiceId->value,
                'posting' => $transaction->posting->value,
                'currency' => $currency,
                'occurred_at' => $occurredAt,
            ]);

            $connection->table('ledger_entries')->insert(array_map(
                static fn(LedgerEntry $entry): array => [
                    'transaction_id' => $transaction->id->value,
                    'organization_id' => $transaction->tenant->organizationId->value,
                    'project_id' => $transaction->tenant->projectId->value,
                    'customer_id' => $transaction->customerId->value,
                    'account' => $entry->account->value,
                    'direction' => $entry->direction->value,
                    'amount_minor' => $entry->amount->minorUnits(),
                    'currency' => $entry->amount->currency(),
                    'occurred_at' => $occurredAt,
                ],
                $transaction->entries,
            ));
        });
    }

    public function balance(TenantContext $tenant, Uuid $customerId, Account $account, string $currency): Money
    {
        $sign = $account->growsWithDebits() ? '' : '-';

        $row = $this->db->connection()->table('ledger_entries')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value)
            ->where('customer_id', $customerId->value)
            ->where('account', $account->value)
            ->where('currency', $currency)
            ->selectRaw("{$sign}coalesce(sum(CASE direction WHEN 'debit' THEN amount_minor ELSE -amount_minor END), 0)::bigint AS balance")
            ->first();

        return Money::ofMinorUnits(RowReader::int(is_object($row) ? get_object_vars($row)['balance'] ?? null : null, 'balance'), $currency);
    }
}
