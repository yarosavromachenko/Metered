<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Simulation\Application\Port\Pacer;
use Metered\Simulation\Application\Traffic\GenerateTraffic;
use Metered\Simulation\Application\Traffic\GenerateTrafficHandler;
use Metered\Tenancy\Domain\Scope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\CatalogFactory;
use Tests\Support\KernelHttp;
use Tests\Support\TenantFactory;
use Tests\Support\UsageStream;

/**
 * A pacer that never waits and remembers what it was asked for.
 */
final class RecordingPacer implements Pacer
{
    /** @var list<float> */
    public array $waits = [];

    public function waitUntil(float $secondsSinceStart): void
    {
        $this->waits[] = $secondsSinceStart;
    }
}

beforeEach(function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-03-15 12:00:00', 'UTC'));
    app()->instance(Pacer::class, new RecordingPacer());
    config(['metered.simulation.api_url' => 'http://metered.test']);
    KernelHttp::route('metered.test');
    UsageStream::isolate();
});

/**
 * A tenant with two customers and two meters, and a key that may read the
 * catalog and write usage.
 */
function trafficToken(): string
{
    $project = TenantFactory::tenant('traffic');
    CatalogFactory::meter($project->tenant(), 'api.requests');
    CatalogFactory::meter($project->tenant(), 'messages.sent', Aggregation::Count);
    CatalogFactory::customer($project->tenant(), 'cus_a');
    CatalogFactory::customer($project->tenant(), 'cus_b');

    return TenantFactory::apiKey($project, [Scope::UsageWrite, Scope::Admin])['secret']->reveal();
}

function pacer(): RecordingPacer
{
    $pacer = app(Pacer::class);

    return $pacer instanceof RecordingPacer ? $pacer : throw new RuntimeException('The recording pacer is not bound.');
}

/**
 * @return list<array<array-key, string>>
 */
function sentEvents(): array
{
    $events = [];

    foreach (Http::recorded(static fn(Request $request): bool => str_ends_with($request->url(), '/usage/events')) as [$request]) {
        $batch = $request->data()['events'] ?? [];

        foreach (is_array($batch) ? $batch : [] as $event) {
            $events[] = is_array($event) ? array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $event) : [];
        }
    }

    return $events;
}

it('sends usage for the tenant\'s own customers and meters, a second at a time', function (): void {
    $report = app(GenerateTrafficHandler::class)->handle(new GenerateTraffic(trafficToken(), eventsPerSecond: 40, seconds: 3, duplicateRate: 0, lateRate: 0));
    $events = sentEvents();

    expect(pacer()->waits)->toBe([0.0, 1.0, 2.0])
        ->and($report->sent)->toBe(120)
        ->and($report->accepted)->toBe(120)
        // Forty events a second in at most five requests.
        ->and($report->requests)->toBe(15)
        ->and(array_values(array_unique(array_column($events, 'customer_ref'))))->toEqualCanonicalizing(['cus_a', 'cus_b'])
        ->and(array_values(array_unique(array_column($events, 'meter_code'))))->toEqualCanonicalizing(['api.requests', 'messages.sent'])
        ->and(array_unique(array_column($events, 'event_id')))->toHaveCount(120)
        ->and(array_values(array_unique(array_column($events, 'occurred_at'))))->toBe(['2026-03-15T12:00:00.000Z']);

    foreach ($events as $event) {
        if ($event['meter_code'] === 'messages.sent') {
            expect($event['quantity'])->toBe('1');
        }
    }
});

it('sends some events twice, some days late, some out of order, and bursts', function (): void {
    $report = app(GenerateTrafficHandler::class)->handle(new GenerateTraffic(trafficToken(), eventsPerSecond: 50, seconds: 15, duplicateRate: 0.05, lateRate: 0.1, outOfOrder: true, burst: true));
    $events = sentEvents();
    $ids = array_column($events, 'event_id');
    $ages = array_map(static fn(array $e): int => strtotime('2026-03-15T12:00:00Z') - (int) strtotime($e['occurred_at']), $events);

    // Fourteen ordinary seconds and one at five times the rate.
    expect($report->sent)->toBe(14 * 50 + 250)
        ->and($report->duplicates)->toBeGreaterThan(10)
        ->and(count($ids) - count(array_unique($ids)))->toBe($report->duplicates)
        ->and($report->late)->toBeGreaterThan(40)
        ->and(count(array_filter($ages, static fn(int $age): bool => $age >= 3_600)))->toBeGreaterThanOrEqual($report->late)
        ->and(max([0, ...$ages]))->toBeLessThanOrEqual(3 * 86_400)
        ->and(count(array_filter($ages, static fn(int $age): bool => $age > 0 && $age <= 600)))->toBeGreaterThan(100);
});

it('refuses to run without a key, and says so', function (): void {
    expect(Artisan::call('sim:traffic'))->toBe(2)
        ->and(Artisan::output())->toContain('--key');
});

it('refuses a tenant with nothing to send usage for', function (): void {
    $empty = TenantFactory::tenant('empty');
    $token = TenantFactory::apiKey($empty, [Scope::UsageWrite, Scope::Admin])['secret']->reveal();

    app(GenerateTrafficHandler::class)->handle(new GenerateTraffic($token, seconds: 1));
})->throws(RuntimeException::class, 'seed it first');
