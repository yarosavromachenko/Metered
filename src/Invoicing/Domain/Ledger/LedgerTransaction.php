<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

use DateTimeImmutable;
use Metered\Invoicing\Domain\CreditNote\CreditNote;
use Metered\Invoicing\Domain\Exception\UnbalancedLedger;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Debits equal credits, checked here and by the database at commit
 * (ADR-0008). One named constructor per kind of posting.
 */
final readonly class LedgerTransaction
{
    /**
     * @param non-empty-list<LedgerEntry> $entries
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $customerId,
        public Uuid $invoiceId,
        public Posting $posting,
        public array $entries,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * @param list<LedgerEntry> $entries
     */
    public static function record(
        Uuid $id,
        TenantContext $tenant,
        Uuid $customerId,
        Uuid $invoiceId,
        Posting $posting,
        array $entries,
        DateTimeImmutable $occurredAt,
    ): self {
        if (count($entries) < 2) {
            throw UnbalancedLedger::tooFewEntries(count($entries));
        }

        [$first] = $entries;
        $debits = Money::zero($first->amount->currency());
        $credits = $debits;

        foreach ($entries as $entry) {
            if ($entry->direction === Direction::Debit) {
                $debits = $debits->plus($entry->amount);
            } else {
                $credits = $credits->plus($entry->amount);
            }
        }

        if (! $debits->equals($credits)) {
            throw UnbalancedLedger::debitsAndCredits((string) $debits, (string) $credits);
        }

        return new self($id, $tenant, $customerId, $invoiceId, $posting, $entries, $occurredAt);
    }

    public static function invoiceFinalized(Uuid $id, Invoice $invoice): self
    {
        return self::move($id, $invoice, Posting::InvoiceFinalized, Account::AccountsReceivable, Account::Revenue, $invoice->total(), $invoice->finalizedAt);
    }

    public static function paymentReceived(Uuid $id, Invoice $invoice): self
    {
        return self::move($id, $invoice, Posting::PaymentReceived, Account::Cash, Account::AccountsReceivable, $invoice->total(), $invoice->paidAt);
    }

    public static function creditNoteIssued(Uuid $id, Invoice $invoice, CreditNote $note): self
    {
        return self::move($id, $invoice, Posting::CreditNoteIssued, Account::Revenue, Account::AccountsReceivable, $note->amount, $note->issuedAt);
    }

    private static function move(
        Uuid $id,
        Invoice $invoice,
        Posting $posting,
        Account $debit,
        Account $credit,
        Money $amount,
        ?DateTimeImmutable $at,
    ): self {
        if (! $at instanceof DateTimeImmutable) {
            throw UnbalancedLedger::notYetHappened($posting->value);
        }

        return self::record(
            $id,
            $invoice->tenant,
            $invoice->customerId,
            $invoice->id,
            $posting,
            [LedgerEntry::debit($debit, $amount), LedgerEntry::credit($credit, $amount)],
            $at,
        );
    }
}
