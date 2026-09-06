<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Metered\Simulation\Application\Chaos\ChaosReport;
use Metered\Simulation\Application\Chaos\Check;
use Metered\Simulation\Application\Chaos\RunChaos;
use Metered\Simulation\Application\Chaos\RunChaosHandler;
use Metered\Simulation\Application\Chaos\Scenario;
use Metered\Simulation\Application\Port\Disruption;
use Metered\Simulation\Application\Port\Waiter;
use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\KernelHttp;
use Tests\Support\UsageStream;

/**
 * Remembers the failures it was asked for instead of causing them: what is
 * under test here is the scenarios' sequence and their checks, run against
 * the real API, consumer, relay and webhook pipeline. The failures themselves
 * are real only on a running stack.
 */
final class RecordedDisruption implements Disruption
{
    /** @var list<string> */
    public array $calls = [];

    public function start(string $command, array $arguments = []): string
    {
        $this->calls[] = trim('start ' . $command . ' ' . implode(' ', $arguments));

        return 'daemon-1';
    }

    public function kill(string $handle): void
    {
        $this->calls[] = 'kill ' . $handle;
    }

    public function pendingOf(string $consumer): int
    {
        return 7;
    }

    public function stallRedis(int $milliseconds): void
    {
        $this->calls[] = 'stall ' . $milliseconds;
    }
}

/**
 * Lets the stack do one round of its daemons' work — consume the stream,
 * relay the outbox, dispatch webhooks — and moves the clock on a minute,
 * between one look at the condition and the next.
 */
final class SteppingWaiter implements Waiter
{
    public function until(Closure $condition, int $timeoutSeconds): ?float
    {
        for ($round = 0; $round < 20; ++$round) {
            if ($condition() === true) {
                return (float) $round;
            }

            Artisan::call('usage:consume', ['--once' => true]);
            Artisan::call('outbox:relay', ['--once' => true]);
            Artisan::call('webhooks:dispatch');

            $clock = app(ClockInterface::class);
            assert($clock instanceof MockClock);
            $clock->modify('+1 minute');
        }

        return null;
    }
}

/**
 * The demo receiver's modes, answered by path.
 */
final class ModeTransport implements WebhookTransport
{
    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult
    {
        return str_ends_with($url->value, '/ok') ? AttemptResult::responded(204, 3, '') : AttemptResult::responded(503, 3, 'down');
    }
}

beforeEach(function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-03-15 12:00:00', 'UTC'));
    app()->instance(Disruption::class, new RecordedDisruption());
    app()->instance(Waiter::class, new SteppingWaiter());
    app()->instance(WebhookTransport::class, new ModeTransport());
    config([
        'metered.simulation.api_url' => 'http://metered.test',
        'metered.simulation.receiver_url' => 'https://receiver.test',
    ]);
    KernelHttp::route('metered.test', ['receiver.test/*' => Http::response('', 303)]);
    UsageStream::isolate();

    // These tests run the consumer, and Redis is not rolled back with the
    // database: events an earlier test left in this process's stream name
    // projects that no longer exist, and consuming them would fail.
    $redis = app(Factory::class)->connection('usage');
    $redis->command('del', [UsageStream::key()]);
    $redis->command('del', [UsageStream::deadLetter()]);
});

function disruption(): RecordedDisruption
{
    $disruption = app(Disruption::class);

    return $disruption instanceof RecordedDisruption ? $disruption : throw new RuntimeException('Not the recorded disruption.');
}

/**
 * @return array<string, bool>
 */
function invariants(ChaosReport $report): array
{
    return array_combine(
        array_map(static fn(Check $check): string => $check->invariant, $report->checks),
        array_map(static fn(Check $check): bool => $check->held, $report->checks),
    );
}

it('kills a consumer mid-run and finds every event counted once', function (): void {
    $report = app(RunChaosHandler::class)->handle(new RunChaos(Scenario::KillConsumer, events: 500));

    expect($report->held())->toBeTrue()
        ->and(invariants($report))->toHaveCount(3)
        ->and(disruption()->calls)->toHaveCount(2)
        ->and(disruption()->calls[0])->toStartWith('start usage:consume --consumer=chaos-')
        ->and(disruption()->calls[1])->toBe('kill daemon-1')
        ->and($report->notes[0])->toContain('held 7 unacknowledged');
});

it('stalls Redis mid-run and finds every event counted once', function (): void {
    $report = app(RunChaosHandler::class)->handle(new RunChaos(Scenario::KillRedisBrief, events: 300));

    expect($report->held())->toBeTrue()
        ->and(disruption()->calls)->toBe(['stall 3000']);
});

it('kills the relay mid-run and finds every event delivered once', function (): void {
    $report = app(RunChaosHandler::class)->handle(new RunChaos(Scenario::KillRelay));

    expect($report->held())->toBeTrue()
        ->and(disruption()->calls)->toBe(['start outbox:relay', 'kill daemon-1']);
});

it('opens the failing endpoint\'s breaker while the healthy one gets everything', function (): void {
    $report = app(RunChaosHandler::class)->handle(new RunChaos(Scenario::FailingWebhook));

    expect(invariants($report))->toBe([
        'the healthy endpoint got every event, whatever the sick one did' => true,
        'the /down endpoint\'s breaker opened' => true,
        'nothing meant for the sick endpoint was lost' => true,
    ]);
});

it('says so, and exits non-zero, when an invariant does not hold', function (): void {
    // A waiter that never lets the stack work: nothing is written, so the
    // count cannot match.
    app()->instance(Waiter::class, new class implements Waiter {
        public function until(Closure $condition, int $timeoutSeconds): ?float
        {
            return $condition() ? 0.0 : null;
        }
    });

    expect(Artisan::call('sim:chaos', ['scenario' => 'kill-redis-brief', '--events' => '100']))->toBe(1);
});

it('refuses a scenario it does not know', function (): void {
    expect(Artisan::call('sim:chaos', ['scenario' => 'unplug-everything']))->toBe(2)
        ->and(Artisan::output())->toContain('kill-consumer, kill-redis-brief, kill-relay, slow-webhook, failing-webhook');
});
