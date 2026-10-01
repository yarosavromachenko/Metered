<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Billing\Application\Contract\CustomerDescriptor;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Billing\Application\Contract\MeterDescriptor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Usage\Domain\AcceptanceWindow;
use Metered\Usage\Domain\EventId;
use Metered\Usage\Domain\Rejection;
use Metered\Usage\Domain\RejectionReason;
use Metered\Usage\Domain\UsageEvent;
use Psr\Clock\ClockInterface;

/**
 * What happens to one batch of events between the stream and the database.
 *
 * The order is deliberate, and each step is cheaper than the next: the window
 * costs arithmetic, resolving the meter and the customer costs a query each
 * per distinct value, the deduplication claim costs a Redis round trip, and
 * the write costs a transaction. An event that will be rejected should be
 * rejected before anything expensive happens to it.
 *
 * Nothing here knows about Redis, consumer groups or acknowledgements. This
 * decides; the daemon around it delivers and acknowledges, which is what makes
 * "what does the system do with a late event" a question answerable by a test
 * with no broker in it.
 */
final readonly class BatchProcessor
{
    public function __construct(
        private MeterCatalog $meters,
        private CustomerDirectory $customers,
        private Deduplicator $deduplicator,
        private EventWriter $writer,
        private RejectionLog $rejections,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
        private AcceptanceWindow $window,
    ) {}

    /**
     * @param  list<IncomingEvent>  $incoming  all belonging to one tenant
     */
    public function process(TenantContext $tenant, array $incoming): IngestionOutcome
    {
        $resolved = [];
        $rejections = [];
        $claimable = [];

        // Resolved once per distinct code and reference rather than once per
        // event: a batch of five hundred is usually a handful of meters, and
        // the difference is five hundred queries or five.
        $meters = [];
        $customers = [];

        foreach ($incoming as $item) {
            $event = $item->event;

            $reason = $this->window->reasonToReject($event->occurredAt, $item->receivedAt);

            if ($reason instanceof RejectionReason) {
                $rejections[] = $this->reject($tenant, $item, $reason, $this->explain($reason));

                continue;
            }

            $meter = $meters[$event->meterCode] ??= $this->meters->find($tenant, $event->meterCode);

            if (! $meter instanceof MeterDescriptor) {
                $rejections[] = $this->reject($tenant, $item, RejectionReason::UnknownMeter, sprintf(
                    'No meter in this project answers to the code "%s".',
                    $event->meterCode,
                ));

                continue;
            }

            $customer = $customers[$event->customerReference] ??= $this->customers->find(
                $tenant,
                $event->customerReference,
            );

            if (! $customer instanceof CustomerDescriptor) {
                $rejections[] = $this->reject($tenant, $item, RejectionReason::UnknownCustomer, sprintf(
                    'No customer in this project is registered as "%s".',
                    $event->customerReference,
                ));

                continue;
            }

            $claimable[$event->eventId->value] = $event->eventId;
            $resolved[$event->eventId->value] = new ResolvedEvent(
                UsageEvent::record(
                    $this->ids->generate(),
                    $tenant,
                    $event->eventId,
                    $customer->id,
                    $meter->id,
                    $event->quantity,
                    $event->occurredAt,
                    $item->receivedAt,
                    $event->properties,
                ),
                $meter->aggregation,
                $meter->code,
                $customer->reference,
            );
        }

        $written = $this->writeClaimed($tenant, $resolved, array_values($claimable));

        // After the write, not before: until it commits the batch has not
        // been dealt with and will be delivered again, and rejections
        // recorded on an attempt that failed would be recorded once more on
        // every retry.
        $this->rejections->record($rejections);

        return $written->plus(new IngestionOutcome(rejected: count($rejections)));
    }

    /**
     * @param  array<string, ResolvedEvent>  $resolved
     * @param  list<EventId>  $claimable
     */
    private function writeClaimed(TenantContext $tenant, array $resolved, array $claimable): IngestionOutcome
    {
        if ($claimable === []) {
            return new IngestionOutcome();
        }

        $events = [];

        foreach ($claimable as $eventId) {
            $events[] = $resolved[$eventId->value]->event;
        }

        $passed = $this->deduplicator->claim($tenant, $events);
        $duplicates = count($claimable) - count($passed);

        if ($passed === []) {
            return new IngestionOutcome(duplicates: $duplicates);
        }

        $toWrite = [];

        foreach ($passed as $event) {
            $toWrite[] = $resolved[$event->eventId->value];
        }

        // No claim is given back if this throws. A claim records the
        // timestamp it was taken for, so the redelivery passes it again and
        // the unique key decides — which is also all that could happen after
        // a crash, when there is nobody left to give anything back.
        $outcome = $this->writer->write($tenant, $toWrite);

        return new IngestionOutcome(
            counted: $outcome->inserted,
            duplicates: $duplicates + $outcome->duplicates,
        );
    }

    private function reject(
        TenantContext $tenant,
        IncomingEvent $item,
        RejectionReason $reason,
        string $detail,
    ): Rejection {
        $event = $item->event;

        return Rejection::of(
            $this->ids->generate(),
            $tenant,
            $reason,
            $detail,
            [
                'event_id' => $event->eventId->value,
                'meter_code' => $event->meterCode,
                'customer_ref' => $event->customerReference,
                'quantity' => (string) $event->quantity,
                'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                'properties' => $event->properties->all(),
            ],
            $this->clock->now(),
        );
    }

    private function explain(RejectionReason $reason): string
    {
        $days = intdiv($this->window->maxAgeSeconds, 86400);
        $minutes = intdiv($this->window->maxDriftSeconds, 60);

        return match ($reason) {
            RejectionReason::TooOld => sprintf(
                'Older than the %d day acceptance window: the period it belongs to may already be invoiced.',
                $days,
            ),
            RejectionReason::InTheFuture => sprintf(
                'More than %d minutes ahead of when it arrived, which is further than a clock plausibly drifts.',
                $minutes,
            ),
            default => $reason->label(),
        };
    }
}
