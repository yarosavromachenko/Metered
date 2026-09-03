<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateInterval;
use DateTimeImmutable;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\MeteredApi;
use Metered\Simulation\Application\Port\TenantProvisioner;
use Metered\Simulation\Application\Port\WebhookInbox;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Seeds one tenant through the public API, in the order a real client would:
 * catalog, webhook endpoints, customers and their subscriptions, then usage.
 *
 * Endpoints come before subscriptions so that the subscriptions' own events
 * are delivered, and the delivery log has something in it from the start.
 * Subscriptions start in the past, where the roster puts them; the periods
 * that have ended since are invoiced by the ordinary period close.
 *
 * Usage covers only the profile's last few days here. The acceptance window
 * refuses anything older, which is what the backfill is for (ADR-0016).
 * Some of it is deliberately wrong: a share is sent twice, and a handful name
 * a meter or a customer that does not exist, so deduplication and rejections
 * have something to show.
 */
final readonly class SeedTenantHandler
{
    private const int BATCH = 100;

    /** Every hundredth event is sent a second time. */
    private const int DUPLICATE_EVERY = 100;

    public function __construct(
        private ApiConnector $connector,
        private TenantProvisioner $provisioner,
        private WebhookInbox $inbox,
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

        $events = $this->liveUsage($command->profile, $noise, $customers, $now);
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
     * @param  list<SeededCustomer>  $customers
     * @return list<array<string, string>>
     */
    private function liveUsage(Profile $profile, Noise $noise, array $customers, DateTimeImmutable $now): array
    {
        $pattern = new UsagePattern($noise, $profile);
        $plans = Catalog::plans();
        $from = $now->sub(new DateInterval(sprintf('P%dD', $profile->liveDays())));
        $events = [];

        foreach ($customers as $customer) {
            for ($hour = $from; $hour <= $now; $hour = $hour->add(new DateInterval('PT1H'))) {
                foreach ($pattern->hour($customer, $plans[$customer->plan]['meters'], $hour) as $event) {
                    // Only what has happened by now, and nothing from before
                    // the window began: the API would refuse both.
                    if ($event->occurredAt <= $now && $event->occurredAt > $from) {
                        $events[] = $event->toApi();
                    }
                }
            }
        }

        return $events;
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

    /**
     * @param  array<string, mixed>  $answer
     */
    private function id(array $answer): string
    {
        $id = $answer['id'] ?? null;

        return is_string($id) ? $id : throw new RuntimeException('The API answered without an id.');
    }
}
