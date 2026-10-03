<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Chaos;

use DateInterval;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\Disruption;
use Metered\Simulation\Application\Port\MeteredApi;
use Metered\Simulation\Application\Port\ProvisionedTenant;
use Metered\Simulation\Application\Port\TenantProvisioner;
use Metered\Simulation\Application\Port\UsageAudit;
use Metered\Simulation\Application\Port\Waiter;
use Metered\Simulation\Application\Port\WebhookInbox;
use Psr\Clock\ClockInterface;

/**
 * Causes one real failure (SIGKILL of a busy daemon, paused Redis) against a
 * fresh organization, waits for the stack to recover, then checks via the API
 * and `usage:reconcile`: no event lost or double-counted, no webhook lost, a
 * failing endpoint does not delay a healthy one.
 */
final readonly class RunChaosHandler
{
    private const int BATCH = 100;

    /** Subscriptions started in the outbox and webhook scenarios. */
    private const int SUBSCRIPTIONS = 12;

    /** Covers the 60s reclaim idle time plus the write. */
    private const int USAGE_RECOVERY_SECONDS = 180;

    private const int DELIVERY_SECONDS = 120;

    private const int REDIS_STALL_MILLISECONDS = 3_000;

    public function __construct(
        private TenantProvisioner $provisioner,
        private ApiConnector $connector,
        private WebhookInbox $inbox,
        private Disruption $disruption,
        private Waiter $waiter,
        private UsageAudit $audit,
        private ClockInterface $clock,
    ) {}

    public function handle(RunChaos $command): ChaosReport
    {
        $say = $command->progress ?? static function (string $line): void {};
        $run = $this->clock->now()->format('YmdHis');
        $tenant = $this->provisioner->provision(sprintf('Chaos %s %s', $command->scenario->value, $run));
        $api = $this->connector->connect($tenant->token);
        $say(sprintf('Organization %s, for this run alone', $tenant->organizationSlug));

        [$checks, $notes] = match ($command->scenario) {
            Scenario::KillConsumer, Scenario::KillRedisBrief => $this->usage($command, $api, $tenant, $run, $say),
            Scenario::KillRelay => $this->relay($api, $say),
            Scenario::SlowWebhook, Scenario::FailingWebhook => $this->webhooks($command->scenario, $api, $say),
        };

        return new ChaosReport($command->scenario, $tenant->organizationSlug, $checks, $notes);
    }

    /**
     * @param  callable(string): void  $say
     * @return array{list<Check>, list<string>}
     */
    private function usage(RunChaos $command, MeteredApi $api, ProvisionedTenant $tenant, string $run, callable $say): array
    {
        $api->write('/meters', ['code' => 'chaos.units', 'name' => 'Chaos units', 'aggregation' => 'sum']);
        $api->write('/customers', ['reference' => 'chaos', 'name' => 'Chaos customer']);

        $start = $this->clock->now();
        $events = max(self::BATCH, $command->events);
        $batches = array_chunk(array_map(
            static fn(int $i): array => ['event_id' => sprintf('chaos-%s-%d', $run, $i), 'meter_code' => 'chaos.units', 'customer_ref' => 'chaos', 'quantity' => '1', 'occurred_at' => $start->format('Y-m-d\TH:i:s.v\Z')],
            range(1, $events),
        ), self::BATCH);
        $split = intdiv(count($batches), $command->scenario === Scenario::KillConsumer ? 2 : 3);
        $notes = [];

        if ($command->scenario === Scenario::KillConsumer) {
            $consumer = 'chaos-' . $run;
            $handle = $this->disruption->start('usage:consume', ['--consumer=' . $consumer]);
            $say(sprintf('Started consumer %s beside the stack\'s own; sending %d events', $consumer, $events));
            $accepted = $api->ingest(array_slice($batches, 0, $split));
            $this->disruption->kill($handle);
            $orphaned = $this->disruption->pendingOf($consumer);
            $say(sprintf('Killed it with SIGKILL, holding %d unacknowledged message(s)', $orphaned));
            $notes[] = sprintf('The killed consumer held %d unacknowledged stream message(s); the stack\'s consumer reclaims them after they idle for a minute.', $orphaned);
        } else {
            $accepted = $api->ingest(array_slice($batches, 0, $split));
            $say(sprintf('Stalling every Redis client for %d ms while ingestion runs', self::REDIS_STALL_MILLISECONDS));
            $this->disruption->stallRedis(self::REDIS_STALL_MILLISECONDS);
            $notes[] = sprintf('Redis held every client for %.1f seconds; batches sent meanwhile were retried with the same event ids.', self::REDIS_STALL_MILLISECONDS / 1000);
        }

        $accepted += $api->ingest(array_slice($batches, $split));
        $say('Waiting for every accepted event to be written');

        $counted = static fn(MeteredApi $api): array => self::counted($api->read('/customers/chaos/usage', [
            'from' => $start->setTime((int) $start->format('G'), 0)->format(DATE_ATOM),
            'to' => $start->add(new DateInterval('PT2H'))->format(DATE_ATOM),
            'meter' => 'chaos.units',
        ]));
        $took = $this->waiter->until(static fn(): bool => $counted($api)['events'] >= $events, self::USAGE_RECOVERY_SECONDS);
        ['quantity' => $quantity, 'events' => $written] = $counted($api);

        if ($took !== null) {
            $notes[] = sprintf('Everything was written %.0f seconds after the last batch.', $took);
        }

        $window = $start->setTime((int) $start->format('G'), 0);

        return [[
            new Check('every event sent was accepted', $accepted === $events, sprintf('%d of %d accepted', $accepted, $events)),
            new Check('every accepted event is counted once — none lost, none twice', $written === $events && $quantity === $events, sprintf('%d events written, quantity %d, expected %d', $written, $quantity, $events)),
            new Check('the aggregates agree with the events under them', $this->audit->reconciled($tenant->projectId, $window, $window->add(new DateInterval('PT2H'))), 'usage:reconcile over the run\'s hours'),
        ], $notes];
    }

    /**
     * @param  callable(string): void  $say
     * @return array{list<Check>, list<string>}
     */
    private function relay(MeteredApi $api, callable $say): array
    {
        $ok = $this->endpoint($api, 'ok');
        $version = $this->plan($api);

        $handle = $this->disruption->start('outbox:relay');
        $say(sprintf('Started a relay beside the stack\'s own; starting %d subscriptions', self::SUBSCRIPTIONS));

        for ($i = 1; $i <= self::SUBSCRIPTIONS; ++$i) {
            $this->subscribe($api, $version, sprintf('chaos-%d', $i));

            if ($i === intdiv(self::SUBSCRIPTIONS, 2)) {
                $this->disruption->kill($handle);
                $say('Killed the relay with SIGKILL, halfway through');
            }
        }

        $took = $this->waiter->until(fn(): bool => $this->deliveries($api, $ok, 'succeeded') >= self::SUBSCRIPTIONS, self::DELIVERY_SECONDS);
        $all = $this->deliveryRows($api, $ok);
        $events = array_unique(array_map(static fn(array $row): string => is_string($row['event_id'] ?? null) ? $row['event_id'] : '', $all));

        return [[
            new Check('every subscription.created reached the endpoint', count($events) === self::SUBSCRIPTIONS, sprintf('%d distinct events delivered, %d expected', count($events), self::SUBSCRIPTIONS)),
            new Check('none reached it twice', count($all) === count($events), sprintf('%d deliveries for %d events', count($all), count($events))),
            new Check('every delivery succeeded', $took !== null, $took !== null ? sprintf('all succeeded within %.0f seconds', $took) : 'not all succeeded in time'),
        ], ['The relay died holding claimed rows; the stack\'s relay picked them up, and the inbox kept the effect single.']];
    }

    /**
     * @param  callable(string): void  $say
     * @return array{list<Check>, list<string>}
     */
    private function webhooks(Scenario $scenario, MeteredApi $api, callable $say): array
    {
        $mode = $scenario === Scenario::SlowWebhook ? 'slow' : 'down';
        $sick = $this->endpoint($api, $mode);
        $ok = $this->endpoint($api, 'ok');
        $version = $this->plan($api);

        $say(sprintf('Two endpoints, /ok and /%s; starting %d subscriptions', $mode, self::SUBSCRIPTIONS));

        for ($i = 1; $i <= self::SUBSCRIPTIONS; ++$i) {
            $this->subscribe($api, $version, sprintf('chaos-%d', $i));
        }

        $say('Waiting for the healthy endpoint, then for the breaker');
        $healthy = $this->waiter->until(fn(): bool => $this->deliveries($api, $ok, 'succeeded') >= self::SUBSCRIPTIONS, self::DELIVERY_SECONDS);
        $opened = $this->waiter->until(fn(): bool => $this->breaker($api, $sick) === 'open', self::DELIVERY_SECONDS);
        $sickRows = $this->deliveryRows($api, $sick);
        $succeeded = count(array_filter($sickRows, static fn(array $row): bool => ($row['status'] ?? null) === 'succeeded'));

        return [[
            new Check('the healthy endpoint got every event, whatever the sick one did', $healthy !== null, $healthy !== null ? sprintf('all %d succeeded within %.0f seconds', self::SUBSCRIPTIONS, $healthy) : 'not all succeeded in time'),
            new Check(sprintf('the /%s endpoint\'s breaker opened', $mode), $opened !== null, sprintf('breaker %s', $this->breaker($api, $sick))),
            new Check('nothing meant for the sick endpoint was lost', count($sickRows) === self::SUBSCRIPTIONS && $succeeded === 0, sprintf('%d deliveries kept for %d events, %d succeeded', count($sickRows), self::SUBSCRIPTIONS, $succeeded)),
        ], ['Deliveries to the sick endpoint wait on the retry schedule and can be replayed once it recovers.']];
    }

    private function endpoint(MeteredApi $api, string $mode): string
    {
        $registered = $api->write('/webhook-endpoints', ['url' => $this->inbox->url($mode), 'events' => ['subscription.created'], 'description' => 'chaos /' . $mode]);
        $secret = $registered['signing_secret'] ?? null;

        if (is_string($secret)) {
            $this->inbox->trust($secret);
        }

        return is_string($registered['id'] ?? null) ? $registered['id'] : '';
    }

    private function plan(MeteredApi $api): string
    {
        $plan = $api->write('/plans', ['code' => 'chaos', 'name' => 'Chaos']);
        $version = $api->write(sprintf('/plans/%s/versions', is_string($plan['id'] ?? null) ? $plan['id'] : ''), ['interval' => 'month', 'prices' => [['model' => 'flat_fee', 'amount' => 100]]]);

        return is_string($version['id'] ?? null) ? $version['id'] : '';
    }

    private function subscribe(MeteredApi $api, string $version, string $reference): void
    {
        $api->write('/customers', ['reference' => $reference, 'name' => ucfirst($reference)]);
        $api->write('/subscriptions', ['customer_ref' => $reference, 'plan_version_id' => $version]);
    }

    private function deliveries(MeteredApi $api, string $endpoint, string $status): int
    {
        return count(array_filter($this->deliveryRows($api, $endpoint), static fn(array $row): bool => ($row['status'] ?? null) === $status));
    }

    /**
     * @return list<array<mixed>>
     */
    private function deliveryRows(MeteredApi $api, string $endpoint): array
    {
        $data = $api->read('/webhook-deliveries', ['endpoint_id' => $endpoint, 'limit' => 100])['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }

    private function breaker(MeteredApi $api, string $endpoint): string
    {
        foreach ((array) ($api->read('/webhook-endpoints')['data'] ?? []) as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $endpoint && is_array($row['breaker'] ?? null)) {
                return is_string($row['breaker']['state'] ?? null) ? $row['breaker']['state'] : 'unknown';
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{quantity: int, events: int}
     */
    private static function counted(array $usage): array
    {
        foreach ((array) ($usage['meters'] ?? []) as $meter) {
            if (is_array($meter) && ($meter['meter_code'] ?? null) === 'chaos.units') {
                return [
                    'quantity' => (int) (is_string($meter['quantity'] ?? null) ? $meter['quantity'] : 0),
                    'events' => is_int($meter['events'] ?? null) ? $meter['events'] : 0,
                ];
            }
        }

        return ['quantity' => 0, 'events' => 0];
    }
}
