<?php

declare(strict_types=1);

use Metered\Simulation\Application\Seed\Catalog;
use Metered\Simulation\Application\Seed\Noise;
use Metered\Simulation\Application\Seed\Profile;
use Metered\Simulation\Application\Seed\Roster;
use Metered\Simulation\Application\Seed\SeededCustomer;
use Metered\Simulation\Application\Seed\SimulatedEvent;
use Metered\Simulation\Application\Seed\UsagePattern;

it('gives the same number for the same seed and key, and another for another seed', function (): void {
    $noise = new Noise(7);

    expect($noise->unit('cus_0001|size'))->toBe(new Noise(7)->unit('cus_0001|size'))
        ->and($noise->unit('cus_0001|size'))->not->toBe(new Noise(8)->unit('cus_0001|size'));

    foreach (range(1, 500) as $i) {
        expect($noise->unit((string) $i))->toBeGreaterThanOrEqual(0.0)->toBeLessThan(1.0)
            ->and($noise->between((string) $i, 3, 5))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5);
    }
});

it('seats the awkward customers where ADR-0016 wants them', function (Profile $profile, string $now): void {
    $now = new DateTimeImmutable($now);
    $customers = new Roster(new Noise(1))->customers($profile, $now);
    $historyStart = $now->modify(sprintf('-%d days', $profile->historyDays()));

    expect($customers)->toHaveCount($profile->customers())
        ->and(array_map(static fn(SeededCustomer $c): string => $c->startsAt->format('d'), array_slice($customers, 0, 3)))->toBe(['29', '30', '31'])
        ->and($customers[3]->silent)->toBeTrue()
        ->and($customers[4]->switchesTo)->toBe('growth')
        ->and($customers[5]->cancels)->toBeTrue()
        ->and($customers[6]->plan)->toBe('scale');

    foreach ($customers as $customer) {
        expect(array_key_exists($customer->plan, Catalog::plans()))->toBeTrue()
            // In the history, and early enough to have a closed period.
            ->and($customer->startsAt >= $historyStart)->toBeTrue()
            ->and($customer->startsAt <= $now->modify('-28 days'))->toBeTrue();
    }
})->with([
    'small, from late September' => [Profile::Small, '2026-09-26T12:00:00Z'],
    'small, just after February' => [Profile::Small, '2026-03-02T12:00:00Z'],
    'demo, from spring' => [Profile::Demo, '2026-04-15T08:00:00Z'],
]);

it('produces the same hour the same way twice, with ids unique inside it', function (): void {
    $pattern = new UsagePattern(new Noise(3), Profile::Demo);
    $customer = new SeededCustomer('cus_0042', 'Blue Harbor Labs', 'growth', new DateTimeImmutable('2026-01-05T10:00:00Z'), 1.4);
    $hour = new DateTimeImmutable('2026-02-11T14:00:00Z');

    $first = $pattern->hour($customer, Catalog::plans()['growth']['meters'], $hour);
    $again = $pattern->hour($customer, Catalog::plans()['growth']['meters'], $hour->modify('+17 minutes'));
    $ids = array_map(static fn(SimulatedEvent $e): string => $e->eventId, $first);

    expect($again)->toEqual($first)
        ->and($ids)->toBe(array_values(array_unique($ids)))
        ->and(array_filter($first, static fn(SimulatedEvent $e): bool => $e->meterCode === 'storage.gb'))->toHaveCount(1);

    foreach ($first as $event) {
        expect($event->occurredAt >= $hour && $event->occurredAt < $hour->modify('+1 hour'))->toBeTrue()
            ->and(in_array($event->meterCode, ['api.requests', 'storage.gb'], true))->toBeTrue();
    }
});

it('sends nothing for a silent customer, or before a customer started', function (): void {
    $pattern = new UsagePattern(new Noise(3), Profile::Demo);
    $start = new DateTimeImmutable('2026-01-05T10:30:00Z');

    expect($pattern->hour(new SeededCustomer('cus_1', 'Quiet', 'growth', $start, 2.0, silent: true), ['api.requests'], $start->modify('+3 days')))->toBe([])
        ->and($pattern->hour(new SeededCustomer('cus_2', 'Early', 'growth', $start, 2.0), ['api.requests'], $start->modify('-1 hour')))->toBe([])
        ->and($pattern->hour(new SeededCustomer('cus_3', 'Punctual', 'growth', $start, 2.0), ['api.requests'], $start))->not->toBe([]);
});

it('sends about as many events as the profile promises', function (Profile $profile): void {
    $pattern = new UsagePattern(new Noise(11), $profile);
    $customer = new SeededCustomer('cus_0001', 'Average', 'starter', new DateTimeImmutable('2026-01-01T00:00:00Z'), 1.0);
    $events = 0;

    // Four whole weeks, so weekends weigh what they weigh in a month.
    for ($hour = new DateTimeImmutable('2026-02-02T00:00:00Z'); $hour < new DateTimeImmutable('2026-03-02T00:00:00Z'); $hour = $hour->modify('+1 hour')) {
        $events += count($pattern->hour($customer, ['api.requests'], $hour));
    }

    expect($events / 28)->toBeGreaterThan($profile->eventsPerCustomerDay() * 0.9)
        ->toBeLessThan($profile->eventsPerCustomerDay() * 1.1);
})->with([Profile::Small, Profile::Demo]);

it('sizes the demo profile at about two million events, and heavy at about twenty', function (): void {
    // Customers average size one and join 15.5 days into the history on
    // average, so volume is customers × days they are there × daily rate.
    $volume = static fn(Profile $p): float => $p->customers() * ($p->historyDays() - 15.5) * $p->eventsPerCustomerDay();

    expect($volume(Profile::Demo))->toBeGreaterThan(1_900_000)->toBeLessThan(2_100_000)
        ->and($volume(Profile::Heavy))->toBeGreaterThan(19_000_000)->toBeLessThan(21_000_000)
        ->and(Profile::Small->liveDays())->toBeLessThanOrEqual(7);
});
