<?php

declare(strict_types=1);

use Metered\Invoicing\Domain\CreditNote\CreditNote;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Invoicing\Domain\Exception\UnbalancedLedger;
use Metered\Invoicing\Domain\Invoice\DocumentNumber;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Direction;
use Metered\Invoicing\Domain\Ledger\LedgerEntry;
use Metered\Invoicing\Domain\Ledger\LedgerTransaction;
use Metered\Invoicing\Domain\Ledger\Posting;
use Metered\Shared\Domain\Exception\CurrencyMismatch;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Tests\Support\InvoiceFixtures;

function ledgerId(): Uuid
{
    return Uuid::fromString('01924b7c-0000-7000-8000-000000000e60');
}

function finalizedInvoice(): Invoice
{
    return InvoiceFixtures::draft()->finalize(DocumentNumber::invoice(1), new DateTimeImmutable('2026-03-01T01:00:05Z'));
}

/**
 * @return list<string> each entry as "debit accounts_receivable 41.50 EUR"
 */
function entriesOf(LedgerTransaction $transaction): array
{
    return array_map(
        static fn(LedgerEntry $entry): string => sprintf('%s %s %s', $entry->direction->value, $entry->account->value, $entry->amount),
        $transaction->entries,
    );
}

/**
 * @param list<LedgerEntry> $entries
 */
function recordEntries(array $entries): LedgerTransaction
{
    return LedgerTransaction::record(
        ledgerId(),
        InvoiceFixtures::tenant(),
        Uuid::fromString('01924b7c-0000-7000-8000-000000000e71'),
        Uuid::fromString('01924b7c-0000-7000-8000-000000000e70'),
        Posting::InvoiceFinalized,
        $entries,
        new DateTimeImmutable('2026-03-01T01:00:05Z'),
    );
}

it('books a finalized invoice as owed and earned', function (): void {
    $invoice = finalizedInvoice();
    $transaction = LedgerTransaction::invoiceFinalized(ledgerId(), $invoice);

    expect(entriesOf($transaction))->toBe([
        'debit accounts_receivable 41.50 EUR',
        'credit revenue 41.50 EUR',
    ])
        ->and($transaction->posting)->toBe(Posting::InvoiceFinalized)
        ->and($transaction->occurredAt)->toBe($invoice->finalizedAt)
        ->and($transaction->invoiceId)->toBe($invoice->id)
        ->and($transaction->customerId)->toBe($invoice->customerId)
        ->and($transaction->tenant)->toBe($invoice->tenant)
        ->and($transaction->id)->toEqual(ledgerId());
});

it('books a payment as cash received against what was owed', function (): void {
    $invoice = finalizedInvoice()->pay(new DateTimeImmutable('2026-03-04T10:00:00Z'));
    $transaction = LedgerTransaction::paymentReceived(ledgerId(), $invoice);

    expect(entriesOf($transaction))->toBe([
        'debit cash 41.50 EUR',
        'credit accounts_receivable 41.50 EUR',
    ])
        ->and($transaction->posting)->toBe(Posting::PaymentReceived)
        ->and($transaction->occurredAt->format(DATE_ATOM))->toBe('2026-03-04T10:00:00+00:00');
});

it('books a credit note as revenue given back', function (): void {
    $voided = finalizedInvoice()->void(new DateTimeImmutable('2026-03-06T10:00:00Z'));
    $note = CreditNote::voiding(Uuid::fromString('01924b7c-0000-7000-8000-000000000e50'), DocumentNumber::creditNote(1), $voided, '  Billed twice by mistake ');
    $transaction = LedgerTransaction::creditNoteIssued(ledgerId(), $voided, $note);

    expect(entriesOf($transaction))->toBe([
        'debit revenue 41.50 EUR',
        'credit accounts_receivable 41.50 EUR',
    ])
        ->and($transaction->posting)->toBe(Posting::CreditNoteIssued)
        ->and($transaction->occurredAt->format(DATE_ATOM))->toBe('2026-03-06T10:00:00+00:00')
        ->and($note->reason)->toBe('Billed twice by mistake')
        ->and((string) $note->number)->toBe('CN-000001')
        ->and((string) $note->amount)->toBe('41.50 EUR')
        ->and($note->invoiceId)->toBe($voided->id)
        ->and($note->customerId)->toBe($voided->customerId)
        ->and($note->tenant)->toBe($voided->tenant);
});

it('credits only a voided invoice that had been finalized', function (Closure $invoice): void {
    /** @var Closure(): Invoice $invoice */
    CreditNote::voiding(Uuid::fromString('01924b7c-0000-7000-8000-000000000e50'), DocumentNumber::creditNote(1), $invoice(), 'x');
})->with([
    'finalized' => finalizedInvoice(...),
    'discarded draft' => fn(): Invoice => InvoiceFixtures::draft()->discard(new DateTimeImmutable()),
])->throws(InvoiceTransitionRefused::class, 'cannot be credited');

it('books nothing for a state the invoice has not reached', function (): void {
    LedgerTransaction::paymentReceived(ledgerId(), finalizedInvoice());
})->throws(UnbalancedLedger::class, 'has not reached that state');

it('refuses a transaction whose debits and credits differ', function (): void {
    recordEntries([
        LedgerEntry::debit(Account::AccountsReceivable, Money::ofMinorUnits(4150, 'EUR')),
        LedgerEntry::credit(Account::Revenue, Money::ofMinorUnits(4149, 'EUR')),
    ]);
})->throws(UnbalancedLedger::class, 'Debits of 41.50 EUR do not equal credits of 41.49 EUR.');

it('refuses a transaction with only one side', function (int $count): void {
    recordEntries(array_fill(0, $count, LedgerEntry::debit(Account::Cash, Money::ofMinorUnits(1, 'EUR'))));
})->with([0, 1])->throws(UnbalancedLedger::class, 'needs a debit and a credit');

it('refuses to balance two currencies against each other', function (): void {
    recordEntries([
        LedgerEntry::debit(Account::Cash, Money::ofMinorUnits(100, 'EUR')),
        LedgerEntry::credit(Account::AccountsReceivable, Money::ofMinorUnits(100, 'USD')),
    ]);
})->throws(CurrencyMismatch::class);

it('balances a split across several entries', function (): void {
    $transaction = recordEntries([
        LedgerEntry::debit(Account::AccountsReceivable, Money::ofMinorUnits(100, 'EUR')),
        LedgerEntry::credit(Account::Revenue, Money::ofMinorUnits(60, 'EUR')),
        LedgerEntry::credit(Account::Revenue, Money::ofMinorUnits(40, 'EUR')),
    ]);

    expect($transaction->entries)->toHaveCount(3)
        ->and($transaction->entries[1]->direction)->toBe(Direction::Credit);
});

it('refuses an entry that moves nothing, or a negative amount', function (int $minorUnits): void {
    LedgerEntry::debit(Account::Cash, Money::ofMinorUnits($minorUnits, 'EUR'));
})->with([0, -100])->throws(UnbalancedLedger::class, 'moves a positive amount');

it('grows receivables and cash with debits, and revenue with credits', function (): void {
    expect(Account::AccountsReceivable->growsWithDebits())->toBeTrue()
        ->and(Account::Cash->growsWithDebits())->toBeTrue()
        ->and(Account::Revenue->growsWithDebits())->toBeFalse();
});
