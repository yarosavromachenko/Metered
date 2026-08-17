<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Domain\Invoice\DocumentNumbering;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Tests\Support\InvoicingScenario;
use Tests\Support\TenantFactory;

/*
 * What the domain types enforce, held again by the schema for rows written
 * without them: a script, a migration, a bug in a repository.
 */

function closedInvoice(): Invoice
{
    $scenario = InvoicingScenario::start()->at('2026-03-01 00:00:00');

    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')),
    );

    return $invoice;
}

it('refuses to change or remove a ledger entry, or its transaction', function (string $statement): void {
    closedInvoice();

    // The balance check is deferred to a commit the suite never makes; run
    // it now, or TRUNCATE refuses for the pending check rather than the rule.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    expect(static fn(): mixed => DB::statement($statement))->toThrow(QueryException::class, 'the ledger is append-only');
})->with([
    'update an entry' => ['UPDATE ledger_entries SET amount_minor = 1'],
    'delete an entry' => ['DELETE FROM ledger_entries'],
    'truncate the entries' => ['TRUNCATE ledger_entries'],
    'update a transaction' => ["UPDATE ledger_transactions SET posting = 'payment.received'"],
    'delete a transaction' => ['DELETE FROM ledger_transactions'],
]);

it('refuses to commit a ledger transaction whose debits and credits differ', function (): void {
    $invoice = closedInvoice();
    $transaction = DB::table('ledger_transactions')->where('invoice_id', $invoice->id->value)->first();

    DB::table('ledger_entries')->insert([
        'transaction_id' => $transaction?->id,
        'organization_id' => $invoice->tenant->organizationId->value,
        'project_id' => $invoice->tenant->projectId->value,
        'customer_id' => $invoice->customerId->value,
        'account' => 'cash',
        'direction' => 'debit',
        'amount_minor' => 1,
        'currency' => 'EUR',
        'occurred_at' => '2026-03-01 00:00:00+00',
    ]);

    // The check is deferred to commit; the suite's wrapping transaction never
    // commits, so it is asked for now.
    expect(static fn(): mixed => DB::statement('SET CONSTRAINTS ALL IMMEDIATE'))->toThrow(QueryException::class, 'does not balance');
});

it('books each movement of an invoice once', function (): void {
    $invoice = closedInvoice();
    $row = (array) DB::table('ledger_transactions')->where('invoice_id', $invoice->id->value)->first();

    expect(static fn(): bool => DB::table('ledger_transactions')->insert([...$row, 'id' => '01924b7c-0000-7000-8000-00000000f0aa']))
        ->toThrow(QueryException::class, 'ledger_transactions_invoice_id_posting_unique');
});

it('refuses to rewrite a finalized invoice or its lines', function (string $statement, string $message): void {
    closedInvoice();

    expect(static fn(): mixed => DB::statement($statement))->toThrow(QueryException::class, $message);
})->with([
    'back to draft' => ["UPDATE invoices SET status = 'draft', number = NULL, finalized_at = NULL", 'cannot change that way'],
    'another total' => ['UPDATE invoices SET total_minor = 1', 'cannot change that way'],
    'another number' => ['UPDATE invoices SET number = 99', 'cannot change that way'],
    'delete it' => ['DELETE FROM invoices', 'cannot be deleted'],
    'change a line' => ['UPDATE invoice_lines SET amount_minor = 1', 'never changed or removed'],
    'remove a line' => ['DELETE FROM invoice_lines', 'never changed or removed'],
]);

it('refuses a line added to an invoice that is no longer a draft', function (): void {
    $invoice = closedInvoice();
    $line = (array) DB::table('invoice_lines')->where('invoice_id', $invoice->id->value)->first();

    expect(static fn(): bool => DB::table('invoice_lines')->insert([...$line, 'position' => 9]))
        ->toThrow(QueryException::class, 'is no longer a draft');
});

it('keeps one invoice per subscription and period, answering a second attempt with false', function (): void {
    $invoice = closedInvoice();
    $again = Invoice::draft(
        Uuid::fromString('01924b7c-0000-7000-8000-00000000f0ab'),
        $invoice->tenant,
        $invoice->customerId,
        $invoice->billTo,
        $invoice->subscriptionId,
        'EUR',
        $invoice->period,
        [],
        new DateTimeImmutable(),
    );

    expect(app(InvoiceRepository::class)->add($again))->toBeFalse()
        ->and(DB::table('invoices')->count())->toBe(1);
});

it('numbers each kind of document on its own, per organization, from one', function (): void {
    $acme = TenantFactory::organization('acme');
    $rival = TenantFactory::organization('north-wind');
    $numbering = app(DocumentNumbering::class);

    $numbers = DB::transaction(static fn(): array => [
        (string) $numbering->nextInvoiceNumber($acme->id),
        (string) $numbering->nextInvoiceNumber($acme->id),
        (string) $numbering->nextCreditNoteNumber($acme->id),
        (string) $numbering->nextInvoiceNumber($rival->id),
    ]);

    expect($numbers)->toBe(['INV-000001', 'INV-000002', 'CN-000001', 'INV-000001']);
});

it('refuses to hand out a number outside a transaction', function (): void {
    // The suite wraps each test in a transaction; this one steps outside it,
    // and writes nothing there — the refusal comes before any statement.
    DB::rollBack();

    try {
        app(DocumentNumbering::class)->nextInvoiceNumber(Uuid::fromString('01924b7c-0000-7000-8000-00000000f0ac'));
    } finally {
        DB::beginTransaction();
    }
})->throws(RuntimeException::class, 'inside the transaction that uses it');
