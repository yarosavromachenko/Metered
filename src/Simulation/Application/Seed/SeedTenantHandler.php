<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateInterval;
use DateTimeImmutable;
use Generator;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\HistoryLoader;
use Metered\Simulation\Application\Port\MeteredApi;
use Metered\Simulation\Application\Port\PeriodCloser;
use Metered\Simulation\Application\Port\TenantProvisioner;
use Metered\Simulation\Application\Port\WebhookInbox;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 *  1. Via the API: catalog, webhook endpoints (first, so subscription events
 *     are delivered), customers, backdated subscriptions.
 *  2. History older than the live days via the bulk loader, then
 *     `usage:reconcile` (ADR-0016).
 *  3. The regular period close.
 *  4. Via the API: most invoices paid, some left overdue, one voided.
 *  5. Via the API: the live days, producing late lines, plus some duplicates
 *     and some events that will be rejected.
 */
final readonly class SeedTenantHandler
{
    private const int BATCH = 100;

    /** Every hundredth event is sent a second time. */
    private const int DUPLICATE_EVERY = 100;

    /** Delay between period end and payment. */
    private const int PAYS_AFTER_DAYS = 10;

    private const float LATE_PAYERS = 0.1;

    public function __construct(
        private ApiConnector $connector,
        private TenantProvisioner $provisioner,
        private WebhookInbox $inbox,
        private HistoryLoader $history,
        private PeriodCloser $periods,
        private ClockInterface $clock,
    ) {}

    public function handle(SeedTenant $command): SeedReport
    {
        $say = $command->progress ?? static function (string $line): void {};
        $now = $this->clock->now();
        $noise = new Noise($command->seed);

        $provisioned = null;
        $token = $command->token;

        if ($token === null) {
            $say(sprintf('Creating organization "%s"', $command->organizationName));
            $provisioned = $this->provisioner->provision($command->organizationName, $command->demo);
            $token = $provisioned->token;
        }

        $api = $this->connector->connect($token);

        $say('Defining meters and plans');
        $meters = $this->meters($api);
        $versions = $this->plans($api);

        $say('Registering webhook endpoints on the demo receiver');
        $endpoints = $this->endpoints($api);

        $customers = new Roster($noise)->customers($command->profile, $now);
        $say(sprintf('Registering %d customers and their subscriptions', count($customers)));
        $this->customers($api, $customers, $versions);

        $pattern = new UsagePattern($noise, $command->profile);
        $liveFrom = $this->hour($now->sub(new DateInterval(sprintf('P%dD', $command->profile->liveDays()))));
        $historyFrom = $this->hour($now->sub(new DateInterval(sprintf('P%dD', $command->profile->historyDays()))));

        $say(sprintf('Loading %d days of history with COPY', $command->profile->historyDays() - $command->profile->liveDays()));
        $history = $this->history->load($token, $this->usage($pattern, $customers, $historyFrom, $liveFrom), $historyFrom, $liveFrom);

        if (! $history->reconciled) {
            throw new RuntimeException('usage:reconcile found drift in the loaded history; the bulk path wrote something the consumer would not have.');
        }

        $say('Closing every period that has ended');
        $this->periods->closeDue();

        $say('Settling invoices');
        [$paid, $voided] = $this->settle($api, $noise, $customers, $now);

        $say(sprintf('Sending the usage of the last %d day(s) through the API', $command->profile->liveDays()));
        $live = $this->liveBatches($this->usage($pattern, $customers, $liveFrom, $now), $this->rejects($customers, $now));
        $accepted = $api->ingest($live);
        ['events' => $events, 'duplicates' => $duplicates, 'rejects' => $rejects] = $live->getReturn();
        $say(sprintf('Sent %d usage events, %d of them resent as duplicates', $events + $duplicates + $rejects, $duplicates));

        return new SeedReport(
            $provisioned?->organizationSlug,
            $provisioned?->token,
            $meters,
            count($versions),
            $endpoints,
            count($customers),
            $history->events,
            $history->aggregates,
            $paid,
            $voided,
            $events + $duplicates + $rejects,
            $accepted,
            $duplicates,
            $rejects,
        );
    }

    private function meters(MeteredApi $api): int
    {
        foreach (Catalog::meters() as $meter) {
            $api->write('/meters', $meter);
        }

        return count(Catalog::meters());
    }

    /**
     * @return array<string, string> plan code => its published version's id
     */
    private function plans(MeteredApi $api): array
    {
        $versions = [];

        foreach (Catalog::plans() as $code => $plan) {
            $planId = $this->id($api->write('/plans', ['code' => $code, 'name' => $plan['name']]));
            $versions[$code] = $this->id($api->write(sprintf('/plans/%s/versions', $planId), ['interval' => 'month', 'prices' => $plan['prices']]));
        }

        return $versions;
    }

    private function endpoints(MeteredApi $api): int
    {
        foreach (Catalog::receiverModes() as $mode => $description) {
            $registered = $api->write('/webhook-endpoints', [
                'url' => $this->inbox->url($mode),
                'events' => Catalog::webhookEvents(),
                'description' => $description,
            ]);

            $secret = $registered['signing_secret'] ?? null;

            if (is_string($secret)) {
                $this->inbox->trust($secret);
            }
        }

        return count(Catalog::receiverModes());
    }

    /**
     * @param  list<SeededCustomer>  $customers
     * @param  array<string, string>  $versions
     */
    private function customers(MeteredApi $api, array $customers, array $versions): void
    {
        foreach ($customers as $customer) {
            $api->write('/customers', ['reference' => $customer->reference, 'name' => $customer->name]);

            $subscription = $this->id($api->write('/subscriptions', [
                'customer_ref' => $customer->reference,
                'plan_version_id' => $versions[$customer->plan],
                'starts_at' => $customer->startsAt->format(DATE_ATOM),
            ]));

            if ($customer->switchesTo !== null) {
                $api->write(sprintf('/subscriptions/%s/change-plan', $subscription), ['plan_version_id' => $versions[$customer->switchesTo]]);
            }

            if ($customer->cancels) {
                $api->write(sprintf('/subscriptions/%s/cancel', $subscription), []);
            }
        }
    }

    /**
     * Generated lazily (a heavy day is ~250k events). Every hundredth event is
     * resent at the end, followed by the events meant to be rejected.
     *
     * @param  iterable<SimulatedEvent>  $usage
     * @param  list<array<string, string>>  $rejects
     * @return Generator<int, list<array<string, string>>, mixed, array{events: int, duplicates: int, rejects: int}>
     */
    private function liveBatches(iterable $usage, array $rejects): Generator
    {
        $batch = [];
        $duplicates = [];
        $events = 0;

        foreach ($usage as $event) {
            $payload = $event->toApi();

            if ($events % self::DUPLICATE_EVERY === 0) {
                $duplicates[] = $payload;
            }

            ++$events;
            $batch[] = $payload;

            if (count($batch) === self::BATCH) {
                yield $batch;
                $batch = [];
            }
        }

        foreach (array_chunk([...$batch, ...$duplicates, ...$rejects], self::BATCH) as $tail) {
            yield $tail;
        }

        return ['events' => $events, 'duplicates' => count($duplicates), 'rejects' => count($rejects)];
    }

    /**
     * Customer by customer, the order the history loader expects.
     *
     * @param  list<SeededCustomer>  $customers
     * @return Generator<SimulatedEvent>
     */
    private function usage(UsagePattern $pattern, array $customers, DateTimeImmutable $from, DateTimeImmutable $to): Generator
    {
        $plans = Catalog::plans();

        foreach ($customers as $customer) {
            for ($hour = $from; $hour < $to; $hour = $hour->add(new DateInterval('PT1H'))) {
                foreach ($pattern->hour($customer, $plans[$customer->plan]['meters'], $hour) as $event) {
                    // No future events.
                    if ($event->occurredAt >= $from && $event->occurredAt < $to) {
                        yield $event;
                    }
                }
            }
        }
    }

    /**
     * Pays due invoices except for late payers; voids one.
     *
     * @param  list<SeededCustomer>  $customers
     * @return array{int, int} paid, voided
     */
    private function settle(MeteredApi $api, Noise $noise, array $customers, DateTimeImmutable $now): array
    {
        $dueBy = $now->sub(new DateInterval(sprintf('P%dD', self::PAYS_AFTER_DAYS)));
        $paid = 0;
        $voided = 0;

        foreach ($customers as $i => $customer) {
            $open = $api->read('/invoices', ['customer_ref' => $customer->reference, 'status' => 'finalized', 'limit' => 100])['data'] ?? [];

            foreach (is_array($open) ? $open : [] as $invoice) {
                $id = is_array($invoice) ? $invoice['id'] ?? null : null;
                $periodEnd = is_array($invoice) ? $invoice['period_end'] ?? null : null;

                if (! is_string($id) || ! is_string($periodEnd) || new DateTimeImmutable($periodEnd) > $dueBy) {
                    continue;
                }

                if ($i === 1 && $voided === 0) {
                    $api->write(sprintf('/invoices/%s/void', $id), ['reason' => 'Issued on the wrong plan; the customer was re-billed by hand.']);
                    ++$voided;

                    continue;
                }

                if ($noise->unit($customer->reference . '|pays') >= self::LATE_PAYERS) {
                    $api->write(sprintf('/invoices/%s/pay', $id), []);
                    ++$paid;
                }
            }
        }

        return [$paid, $voided];
    }

    /**
     * A misspelled meter code and an unregistered customer.
     *
     * @param  list<SeededCustomer>  $customers
     * @return list<array<string, string>>
     */
    private function rejects(array $customers, DateTimeImmutable $now): array
    {
        $at = $now->sub(new DateInterval('PT2H'))->format('Y-m-d\TH:i:s\Z');
        $events = [];

        foreach (array_slice($customers, 0, 3) as $i => $customer) {
            $events[] = ['event_id' => 'sim-typo-' . $i, 'meter_code' => 'api.request', 'customer_ref' => $customer->reference, 'quantity' => '1', 'occurred_at' => $at];
        }

        $events[] = ['event_id' => 'sim-stranger-0', 'meter_code' => 'api.requests', 'customer_ref' => 'cus_unregistered', 'quantity' => '1', 'occurred_at' => $at];

        return $events;
    }

    private function hour(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTime((int) $at->format('G'), 0);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function id(array $answer): string
    {
        $id = $answer['id'] ?? null;

        return is_string($id) ? $id : throw new RuntimeException('The API answered without an id.');
    }
}
