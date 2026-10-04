<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Traffic;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Metered\Simulation\Application\Port\ApiConnector;
use Metered\Simulation\Application\Port\MeteredApi;
use Metered\Simulation\Application\Port\Pacer;
use Metered\Simulation\Application\Seed\Noise;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Reads customers and meters from the API, so it works on any tenant. Event
 * ids include the run's start time, so separate runs are not deduplicated.
 */
final readonly class GenerateTrafficHandler
{
    private const int MAX_REQUESTS_PER_SECOND = 5;

    private const int MAX_BATCH = 100;

    private const int BURST_EVERY = 15;

    private const int BURST_FACTOR = 5;

    /** How many recent events a duplicate may be drawn from. */
    private const int RECENT = 500;

    public function __construct(
        private ApiConnector $connector,
        private Pacer $pacer,
        private ClockInterface $clock,
    ) {}

    public function handle(GenerateTraffic $command): TrafficReport
    {
        $api = $this->connector->connect($command->token);
        $customers = $this->references($api);
        $meters = $this->meters($api);

        if ($customers === [] || $meters === []) {
            throw new RuntimeException('The tenant has no customers or no meters to send usage for; seed it first.');
        }

        $noise = new Noise($command->seed);
        $run = $this->clock->now()->format('YmdHis');
        $recent = [];
        $sent = $accepted = $duplicates = $late = $requests = 0;

        for ($second = 0; $second < $command->seconds; ++$second) {
            $this->pacer->waitUntil($second);

            $count = $command->burst && $second % self::BURST_EVERY === self::BURST_EVERY - 1
                ? $command->eventsPerSecond * self::BURST_FACTOR
                : $command->eventsPerSecond;
            $now = $this->clock->now();
            $events = [];

            for ($i = 0; $i < $count; ++$i) {
                $key = sprintf('%s|%d|%d', $run, $second, $i);

                if ($recent !== [] && $noise->unit($key . '|dup') < $command->duplicateRate) {
                    $events[] = $noise->pick($key . '|which', $recent);
                    ++$duplicates;

                    continue;
                }

                $isLate = $noise->unit($key . '|late') < $command->lateRate;
                $late += $isLate ? 1 : 0;

                $event = $this->event($noise, $key, sprintf('traffic-%s-%d-%d', $run, $second, $i), $customers, $meters, $this->occurredAt($noise, $key, $now, $isLate, $command->outOfOrder));
                $events[] = $event;
                $recent[] = $event;
            }

            $recent = array_slice($recent, -self::RECENT);
            $batches = array_chunk($events, max(1, min(self::MAX_BATCH, (int) ceil(count($events) / self::MAX_REQUESTS_PER_SECOND))));
            $accepted += $api->ingest($batches);
            $requests += count($batches);
            $sent += count($events);

            if ($command->progress instanceof Closure) {
                ($command->progress)($second + 1, $sent);
            }
        }

        return new TrafficReport($sent, $accepted, $duplicates, $late, $requests);
    }

    /**
     * @param  non-empty-list<string>  $customers
     * @param  non-empty-list<array{code: string, aggregation: string}>  $meters
     * @return array<string, string>
     */
    private function event(Noise $noise, string $key, string $id, array $customers, array $meters, DateTimeImmutable $at): array
    {
        $meter = $noise->pick($key . '|meter', $meters);

        return [
            'event_id' => $id,
            'meter_code' => $meter['code'],
            'customer_ref' => $noise->pick($key . '|customer', $customers),
            'quantity' => match ($meter['aggregation']) {
                'count' => '1',
                'max' => sprintf('%.3f', 5 + 95 * $noise->unit($key . '|q')),
                default => (string) $noise->between($key . '|q', 1, 40),
            },
            'occurred_at' => $at->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function occurredAt(Noise $noise, string $key, DateTimeImmutable $now, bool $late, bool $outOfOrder): DateTimeImmutable
    {
        $back = match (true) {
            $late => $noise->between($key . '|back', 3_600, 3 * 86_400),
            $outOfOrder => $noise->between($key . '|back', 0, 600),
            default => 0,
        };

        return $now->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval(sprintf('PT%dS', $back)));
    }

    /**
     * @return list<string>
     */
    private function references(MeteredApi $api): array
    {
        $references = [];

        foreach ($this->rows($api->read('/customers')) as $row) {
            if (is_string($row['reference'] ?? null)) {
                $references[] = $row['reference'];
            }
        }

        return $references;
    }

    /**
     * @return list<array{code: string, aggregation: string}>
     */
    private function meters(MeteredApi $api): array
    {
        $meters = [];

        foreach ($this->rows($api->read('/meters')) as $row) {
            if (is_string($row['code'] ?? null) && is_string($row['aggregation'] ?? null)) {
                $meters[] = ['code' => $row['code'], 'aggregation' => $row['aggregation']];
            }
        }

        return $meters;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<mixed>>
     */
    private function rows(array $answer): array
    {
        $data = $answer['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, is_array(...))) : [];
    }
}
