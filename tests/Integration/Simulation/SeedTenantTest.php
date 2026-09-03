<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Metered\Simulation\Application\Seed\Profile;
use Metered\Simulation\Application\Seed\SeedTenant;
use Metered\Simulation\Application\Seed\SeedTenantHandler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\KernelHttp;
use Tests\Support\UsageStream;

beforeEach(function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-03-15 12:00:00', 'UTC'));
    config([
        'metered.simulation.api_url' => 'http://metered.test',
        'metered.simulation.receiver_url' => 'https://receiver.test',
    ]);
    KernelHttp::route('metered.test', ['receiver.test/*' => Http::response('', 303)]);
    UsageStream::isolate();
});

/**
 * @return list<string>
 */
function trustedSecrets(): array
{
    return array_values(array_map(
        static fn(array $pair): string => is_string($pair[0]->data()['secret'] ?? null) ? $pair[0]->data()['secret'] : '',
        Http::recorded(static fn(Request $request): bool => str_ends_with($request->url(), '/_secrets'))->all(),
    ));
}

it('seeds a new tenant through the API, the way a client would', function (): void {
    $report = app(SeedTenantHandler::class)->handle(new SeedTenant(Profile::Small, seed: 1, organizationName: 'Northwind Cloud'));
    $organization = DB::table('organizations')->where('slug', 'northwind-cloud')->value('id');

    expect($report->organizationSlug)->toBe('northwind-cloud')
        ->and($report->token)->toStartWith('mk_test_')
        ->and($report->eventsAccepted)->toBe($report->eventsSent)
        ->and($report->eventsSent)->toBeGreaterThan(1_000)
        ->and($report->duplicatesSent)->toBeGreaterThan(0)
        ->and($report->rejectsSent)->toBe(4)
        ->and(DB::table('meters')->where('organization_id', $organization)->orderBy('code')->pluck('code')->all())
        ->toBe(['api.requests', 'compute.minutes', 'messages.sent', 'storage.gb'])
        ->and(DB::table('plan_versions')->where('organization_id', $organization)->whereNotNull('published_at')->count())->toBe(4)
        ->and(DB::table('customers')->where('organization_id', $organization)->count())->toBe(12);

    // Every pricing model is on a published version.
    expect(DB::table('prices')->where('organization_id', $organization)->distinct()->orderBy('model')->pluck('model')->all())
        ->toBe(['flat_fee', 'graduated', 'per_unit', 'volume']);

    // Subscriptions start where the roster put them, in the past.
    $subscriptions = DB::table('subscriptions as s')->join('customers as c', 'c.id', '=', 's.customer_id')
        ->where('s.organization_id', $organization)->orderBy('c.reference');
    $anchors = $subscriptions->clone()->pluck('s.anchor_at')->map(static fn(mixed $at): string => substr(is_string($at) ? $at : '', 0, 16))->all();

    expect($anchors)->toHaveCount(12)
        ->and(array_slice($anchors, 0, 3))->toBe(['2026-01-29 10:00', '2026-01-30 10:00', '2026-01-31 10:00'])
        ->and($subscriptions->clone()->pluck('s.status')->all()[5] ?? null)->toBe('pending_cancellation')
        ->and(DB::table('subscription_phases as p')->join('subscriptions as s', 's.id', '=', 'p.subscription_id')->join('customers as c', 'c.id', '=', 's.customer_id')->where('c.reference', 'cus_0005')->count())->toBe(2);

    // One endpoint per receiver mode, each secret handed to the receiver.
    expect(DB::table('webhook_endpoints')->where('organization_id', $organization)->orderBy('url')->pluck('url')->all())->toBe([
        'https://receiver.test/down', 'https://receiver.test/flaky', 'https://receiver.test/gone', 'https://receiver.test/ok', 'https://receiver.test/slow',
    ])->and(trustedSecrets())->toHaveCount(5)
        ->and(array_filter(trustedSecrets(), static fn(string $secret): bool => str_starts_with($secret, 'whsec_')))->toHaveCount(5);

    // Written through the ordinary API: every change is audited as the key.
    expect(DB::table('audit_log')->where('action', 'subscription.started')->where('actor', 'like', 'api-key:%')->count())->toBe(12);
});

it('seeds the tenant a key belongs to, and seeds the same data from the same seed', function (): void {
    Artisan::call('org:create', ['name' => 'Visitor', '--json' => true]);
    /** @var array{key: array{secret: string}} $created */
    $created = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $first = app(SeedTenantHandler::class)->handle(new SeedTenant(Profile::Small, seed: 5, token: $created['key']['secret']));
    $names = DB::table('customers')->orderBy('reference')->pluck('name')->all();

    expect($first->organizationSlug)->toBeNull()
        ->and($first->token)->toBeNull()
        ->and(DB::table('organizations')->count())->toBe(1)
        ->and($names)->toHaveCount(12);

    Artisan::call('org:create', ['name' => 'Second visitor', '--json' => true]);
    /** @var array{key: array{secret: string}, organization: array{id: string}} $again */
    $again = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    $second = app(SeedTenantHandler::class)->handle(new SeedTenant(Profile::Small, seed: 5, token: $again['key']['secret']));

    expect($second->eventsSent)->toBe($first->eventsSent)
        ->and(DB::table('customers')->where('organization_id', $again['organization']['id'])->orderBy('reference')->pluck('name')->all())->toBe($names);
});

it('runs from the console, and refuses a profile it does not know', function (): void {
    // Its own buffer: the org:create it runs would replace Artisan::output().
    $output = new BufferedOutput();

    expect(Artisan::call('sim:seed', ['--profile' => 'enormous']))->toBe(2)
        ->and(Artisan::call('sim:seed', ['--profile' => 'small', '--organization' => 'Console Co'], $output))->toBe(0)
        ->and($output->fetch())->toContain('Seeded the small profile')->toContain('console-co')->toContain('mk_test_');
});
