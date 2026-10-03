<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Command;

use DateTimeImmutable;
use Metered\Billing\Application\Contract\BillableSubscription;
use Metered\Billing\Application\Contract\Charge;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Invoicing\Domain\Invoice\BillingHistory;
use Metered\Invoicing\Domain\Invoice\BillTo;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Invoice\LateUsage;
use Metered\Invoicing\Domain\Invoice\MeterCharge;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Usage\Application\Contract\UsageTotals;
use Psr\Clock\ClockInterface;

/**
 * For each period past its grace window: a draft from the aggregates, plus
 * late lines for earlier periods, then finalization. Idempotent: a unique key
 * on (subscription, period) makes a second run a no-op (ADR-0010).
 */
final readonly class CloseSubscriptionPeriodsHandler
{
    /**
     * @param int $graceSeconds      how long after a period ends it may be closed
     * @param int $lateWindowSeconds acceptance window plus grace
     */
    public function __construct(
        private SubscriptionBilling $billing,
        private UsageTotals $usage,
        private InvoiceRepository $invoices,
        private BillingHistory $history,
        private FinalizeInvoiceHandler $finalize,
        private Transactions $transactions,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private int $graceSeconds,
        private int $lateWindowSeconds,
    ) {}

    /**
     * @return list<Invoice> the invoices this run built, finalized
     */
    public function handle(CloseSubscriptionPeriods $command): array
    {
        $subscription = $this->billing->find($command->tenant, $command->subscriptionId);

        if (! $subscription instanceof BillableSubscription) {
            throw InvoiceNotFound::subscription($command->subscriptionId);
        }

        $now = $this->clock->now();
        $from = $this->invoices->latestFor($command->tenant, $subscription->id)?->period->end ?? $subscription->anchorAt;
        $closable = $now->modify(sprintf('-%d seconds', $this->graceSeconds));
        $closed = [];

        foreach ($this->billing->periodsEndedBy($command->tenant, $subscription->id, $from, $closable) as $ended) {
            $period = InvoicePeriod::between($ended->start, $ended->end);
            $draft = $this->transactions->run(fn(): ?Invoice => $this->build($subscription, $period, $now));

            if ($draft instanceof Invoice) {
                $closed[] = $this->finalize->handle(new FinalizeInvoice($command->tenant, $draft->id, $command->actor));
            }
        }

        $this->billing->lapse($command->tenant, $subscription->id, $now);

        return $closed;
    }

    private function build(BillableSubscription $subscription, InvoicePeriod $period, DateTimeImmutable $now): ?Invoice
    {
        $usage = $this->usage->forPeriod($subscription->tenant, $subscription->customerId, $period->start, $period->end);
        $lines = [];

        foreach ($this->billing->charges($subscription->tenant, $subscription->id, $period->start, $usage) as $charge) {
            $metered = $this->meterCharge($charge);

            $lines[] = $metered instanceof MeterCharge
                ? InvoiceLine::usage($metered, $period)
                : InvoiceLine::fixed($charge->priceId, 'Flat fee', $charge->amount, $period, $charge->calculation);
        }

        $draft = Invoice::draft(
            $this->ids->generate(),
            $subscription->tenant,
            $subscription->customerId,
            new BillTo($subscription->customerReference, $subscription->customerName),
            $subscription->id,
            $subscription->currency,
            $period,
            [...$lines, ...$this->lateLines($subscription, $period)],
            $now,
        );

        return $this->invoices->add($draft) ? $draft : null;
    }

    /**
     * @return list<InvoiceLine>
     */
    private function lateLines(BillableSubscription $subscription, InvoicePeriod $period): array
    {
        $reachable = $period->start->modify(sprintf('-%d seconds', $this->lateWindowSeconds));
        $lines = [];

        foreach ($this->history->periodsEndedAfter($subscription->tenant, $subscription->id, $reachable) as $earlier) {
            if ($earlier->end > $period->start) {
                continue;
            }

            $billed = $this->history->billedQuantities($subscription->tenant, $subscription->id, $earlier);
            $now = $this->usage->forPeriod($subscription->tenant, $subscription->customerId, $earlier->start, $earlier->end);

            if (! $this->grew($billed, $now)) {
                continue;
            }

            // Priced at the billed and the current quantity, same version and order.
            $before = $this->billing->charges($subscription->tenant, $subscription->id, $earlier->start, $billed);
            $after = $this->billing->charges($subscription->tenant, $subscription->id, $earlier->start, $now);

            foreach ($after as $index => $charge) {
                $then = $this->meterCharge($before[$index]);
                $current = $this->meterCharge($charge);

                if ($then instanceof MeterCharge && $current instanceof MeterCharge) {
                    $line = LateUsage::line($then, $current, $earlier);

                    if ($line instanceof InvoiceLine) {
                        $lines[] = $line;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @param array<string, Quantity> $billed
     * @param array<string, Quantity> $now
     */
    private function grew(array $billed, array $now): bool
    {
        return array_any($now, fn(Quantity $quantity, $meterId): bool => $quantity->compareTo($billed[$meterId] ?? Quantity::zero()) > 0);
    }

    private function meterCharge(Charge $charge): ?MeterCharge
    {
        if (! $charge->meterId instanceof Uuid || $charge->meterCode === null || ! $charge->quantity instanceof Quantity) {
            return null;
        }

        return new MeterCharge($charge->priceId, $charge->meterId, $charge->meterCode, $charge->quantity, $charge->amount, $charge->calculation);
    }
}
