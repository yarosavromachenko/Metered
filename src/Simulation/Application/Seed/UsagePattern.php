<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Hourly usage: daily peak at 14:00 UTC, quieter weekends, scaled by customer
 * size. Storage is an hourly, slowly growing gauge, so its `max` grows per
 * period. Keyed noise makes every hour reproducible.
 */
final readonly class UsagePattern
{
    public function __construct(
        private Noise $noise,
        private Profile $profile,
    ) {}

    /**
     * @param  list<string>  $meters  the meters the customer's plan prices
     * @return list<SimulatedEvent>
     */
    public function hour(SeededCustomer $customer, array $meters, DateTimeImmutable $hour): array
    {
        $hour = $hour->setTimezone(new DateTimeZone('UTC'));
        $hour = $hour->setTime((int) $hour->format('G'), 0);

        if ($customer->silent || $hour < $customer->startsAt->setTime((int) $customer->startsAt->format('G'), 0)) {
            return [];
        }

        $key = $customer->reference . '|' . $hour->format('YmdH');
        $events = [];

        if (in_array('storage.gb', $meters, true)) {
            $events[] = $this->storage($customer, $hour, $key);
        }

        $flowing = array_values(array_filter($meters, static fn(string $meter): bool => $meter !== 'storage.gb'));

        if ($flowing === []) {
            return $events;
        }

        $count = $this->count($customer, $hour, $key);

        for ($n = 0; $n < $count; ++$n) {
            $meter = $this->noise->pick($key . '|' . $n . '|meter', $flowing);
            $events[] = new SimulatedEvent(
                sprintf('sim-%s-%s-%d', $customer->reference, $hour->format('YmdH'), $n),
                $meter,
                $customer->reference,
                $this->quantity($meter, $key . '|' . $n),
                $hour->add(new DateInterval(sprintf('PT%dS', $this->noise->between($key . '|' . $n . '|second', 0, 3599)))),
            );
        }

        return $events;
    }

    private function count(SeededCustomer $customer, DateTimeImmutable $hour, string $key): int
    {
        // Sine peaking at 14:00 UTC; weekends at 0.6; both average to one.
        $daily = 1 + 0.6 * sin(2 * M_PI * ((int) $hour->format('G') - 8) / 24);
        $weekly = (int) $hour->format('N') >= 6 ? 0.6 : 1.16;
        $expected = $this->profile->eventsPerCustomerDay() / 24 * $customer->size * $daily * $weekly;

        // Probabilistic rounding: 0.3 per hour means one event every ~3 hours.
        $whole = (int) floor($expected);

        return $whole + ($this->noise->unit($key . '|count') < $expected - $whole ? 1 : 0);
    }

    private function quantity(string $meter, string $key): string
    {
        return match ($meter) {
            'api.requests' => (string) $this->noise->between($key . '|q', 1, 40),
            'compute.minutes' => sprintf('%.1f', $this->noise->between($key . '|q', 1, 300) / 10),
            default => '1',
        };
    }

    private function storage(SeededCustomer $customer, DateTimeImmutable $hour, string $key): SimulatedEvent
    {
        $days = max(0, (int) floor(($hour->getTimestamp() - $customer->startsAt->getTimestamp()) / 86_400));
        $gigabytes = 20 * $customer->size * (1 + 0.01 * $days) * (0.97 + 0.06 * $this->noise->unit($key . '|storage'));

        return new SimulatedEvent(
            sprintf('sim-%s-%s-storage', $customer->reference, $hour->format('YmdH')),
            'storage.gb',
            $customer->reference,
            sprintf('%.3f', $gigabytes),
            $hour->add(new DateInterval('PT59M')),
        );
    }
}
