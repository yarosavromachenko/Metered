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
 * Seeds one tenant, in the order a real client and a real history would
 * leave it:
 *
 *  1. catalog, webhook endpoints, customers and subscriptions — through the
 *     API. Endpoints come first so the subscriptions' own events are
 *     delivered; subscriptions start in the past, where the roster puts them.
 *  2. history up to the profile's last few days — by the bulk loader, since
 *     the acceptance window refuses anything older (ADR-0016), and checked by
 *     `usage:reconcile` before anything is built on it.
 *  3. every period that has ended is closed, by the ordinary period close.
 *  4. those invoices are settled through the API the way customers settle:
 *     most paid some days after they were issued, a few left overdue, one
 *     voided with a credit note.
 *  5. the last few days of usage — through the API. Some of it falls in
 *     periods closed in step 3 and becomes late lines on the next invoice; a
 *     share is sent twice, and a handful name a meter or customer that does
 *     not exist, so deduplication and rejections have something to show.
 */
final readonly class SeedTenantHandler
{
    private const int BATCH = 100;

    /** Every hundredth event is sent a second time. */
    private const int DUPLICATE_EVERY = 100;

    /** How long after an invoice's period ends a customer who pays, pays. */
    private const int PAYS_AFTER_DAYS = 10;

    /** The share of customers who leave their invoices open. */
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
            $provisioned = $this->provisioner->provision($command->organizationName);
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

        $events = array_map(
            static fn(SimulatedEvent $event): array => $event->toApi(),
            iterator_to_array($this->usage($pattern, $customers, $liveFrom, $now), false),
        );
        $duplicates = array_values(array_filter($events, static fn(array $event, int $i): bool => $i % self::DUPLICATE_EVERY === 0, ARRAY_FILTER_USE_BOTH));
        $rejects = $this->rejects($customers, $now);
        $say(sprintf('Sending %d usage events from the last %d day(s)', count($events), $command->profile->liveDays()));
        $accepted = $api->ingest(array_chunk([...$events, ...$duplicates, ...$rejects], self::BATCH));

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
            count($events) + count($duplicates) + count($rejects),
            $accepted,
            count($duplicates),
            count($rejects),
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
     * Every customer's usage in [from, to), one customer after another — the
     * order the history loader folds aggregates in.
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
                    // Nothing that has not happened yet: the API would refuse it.
                    if ($event->occurredAt >= $from && $event->occurredAt < $to) {
                        yield $event;
                    }
                }
            }
        }
    }

    /**
     * Pays what has been open long enough, except for the few customers who
     * never pay on time; voids one invoice instead of paying it.
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
     * A few events a real integration gets wrong: a meter code with a typo,
     * and a customer nobody registered.
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
