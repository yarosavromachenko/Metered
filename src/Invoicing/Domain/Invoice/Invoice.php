<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use DateTimeImmutable;
use Metered\Invoicing\Domain\Exception\InvalidInvoice;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * One period of one subscription. Draft → finalized (numbered, booked) → paid
 * or voided by a credit note. Never edited after finalization (docs/domain.md,
 * invariant 10).
 */
final readonly class Invoice
{
    /**
     * @param list<InvoiceLine> $lines
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public Uuid $customerId,
        public BillTo $billTo,
        public Uuid $subscriptionId,
        public string $currency,
        public InvoicePeriod $period,
        public array $lines,
        public InvoiceStatus $status,
        public ?DocumentNumber $number,
        public DateTimeImmutable $builtAt,
        public ?DateTimeImmutable $finalizedAt,
        public ?DateTimeImmutable $paidAt,
        public ?DateTimeImmutable $voidedAt,
    ) {}

    /**
     * @param list<InvoiceLine> $lines
     */
    public static function draft(
        Uuid $id,
        TenantContext $tenant,
        Uuid $customerId,
        BillTo $billTo,
        Uuid $subscriptionId,
        string $currency,
        InvoicePeriod $period,
        array $lines,
        DateTimeImmutable $builtAt,
    ): self {
        foreach ($lines as $line) {
            if ($line->amount->currency() !== $currency) {
                throw InvalidInvoice::lineCurrency($currency, $line->amount->currency());
            }

            if ($line->kind === LineKind::Late && $line->covers->end > $period->start) {
                throw InvalidInvoice::lateLineForItsOwnPeriod();
            }
        }

        return new self(
            $id,
            $tenant,
            $customerId,
            $billTo,
            $subscriptionId,
            $currency,
            $period,
            $lines,
            InvoiceStatus::Draft,
            null,
            $builtAt,
            null,
            null,
            null,
        );
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @internal for the repository
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        Uuid $customerId,
        BillTo $billTo,
        Uuid $subscriptionId,
        string $currency,
        InvoicePeriod $period,
        array $lines,
        InvoiceStatus $status,
        ?DocumentNumber $number,
        DateTimeImmutable $builtAt,
        ?DateTimeImmutable $finalizedAt,
        ?DateTimeImmutable $paidAt,
        ?DateTimeImmutable $voidedAt,
    ): self {
        return new self(
            $id,
            $tenant,
            $customerId,
            $billTo,
            $subscriptionId,
            $currency,
            $period,
            $lines,
            $status,
            $number,
            $builtAt,
            $finalizedAt,
            $paidAt,
            $voidedAt,
        );
    }

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            static fn(Money $total, InvoiceLine $line): Money => $total->plus($line->amount),
            Money::zero($this->currency),
        );
    }

    /**
     * A zero invoice is marked paid immediately.
     */
    public function finalize(DocumentNumber $number, DateTimeImmutable $at): self
    {
        $this->guard(InvoiceStatus::Draft, 'finalized');

        $settled = $this->total()->isZero();

        return $this->with(
            $settled ? InvoiceStatus::Paid : InvoiceStatus::Finalized,
            $number,
            finalizedAt: $at,
            paidAt: $settled ? $at : null,
        );
    }

    public function pay(DateTimeImmutable $at): self
    {
        $this->guard(InvoiceStatus::Finalized, 'paid');

        return $this->with(InvoiceStatus::Paid, $this->number, $this->finalizedAt, paidAt: $at);
    }

    public function discard(DateTimeImmutable $at): self
    {
        $this->guard(InvoiceStatus::Draft, 'discarded');

        return $this->with(InvoiceStatus::Void, null, null, null, voidedAt: $at);
    }

    /**
     * The credit note reverses the entries; the invoice keeps its number.
     */
    public function void(DateTimeImmutable $at): self
    {
        $this->guard(InvoiceStatus::Finalized, 'voided');

        return $this->with(InvoiceStatus::Void, $this->number, $this->finalizedAt, null, voidedAt: $at);
    }

    private function guard(InvoiceStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw InvoiceTransitionRefused::from($this->status->value, $action);
        }
    }

    private function with(
        InvoiceStatus $status,
        ?DocumentNumber $number,
        ?DateTimeImmutable $finalizedAt,
        ?DateTimeImmutable $paidAt = null,
        ?DateTimeImmutable $voidedAt = null,
    ): self {
        return new self(
            $this->id,
            $this->tenant,
            $this->customerId,
            $this->billTo,
            $this->subscriptionId,
            $this->currency,
            $this->period,
            $this->lines,
            $status,
            $number,
            $this->builtAt,
            $finalizedAt,
            $paidAt,
            $voidedAt,
        );
    }
}
