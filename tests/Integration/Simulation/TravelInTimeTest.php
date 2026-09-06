<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Simulation\Application\Port\TimeMachine;
use Metered\Simulation\Application\Time\TravelInTime;
use Metered\Simulation\Application\Time\TravelInTimeHandler;
use Metered\Simulation\Application\Time\Travelled;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InvoicingScenario;

/**
 * Moves the test's MockClock the way the travelling clock moves every
 * process of a running stack.
 */
final class MockClockTimeMachine implements TimeMachine
{
    private int $offset = 0;

    public function __construct(private readonly MockClock $clock) {}

    public function offsetSeconds(): int
    {
        return $this->offset;
    }

    public function setOffset(int $seconds): void
    {
        $this->clock->modify(sprintf('%+d seconds', $seconds - $this->offset));
        $this->offset = $seconds;
    }
}

function travel(TravelInTime $command): Travelled
{
    // One machine per test, over the clock the scenario bound.
    if (! app()->bound(MockClockTimeMachine::class)) {
        $clock = app(ClockInterface::class);
        app()->instance(MockClockTimeMachine::class, new MockClockTimeMachine($clock instanceof MockClock ? $clock : throw new RuntimeException('Bind a MockClock first.')));
    }

    app()->instance(TimeMachine::class, app(MockClockTimeMachine::class));

    return app(TravelInTimeHandler::class)->handle($command);
}

it('moves a month ahead and closes the periods that ended on the way', function (): void {
    $scenario = InvoicingScenario::start('2026-01-31 14:00:00')->at('2026-02-10 09:00:00');

    $travelled = travel(new TravelInTime(by: new DateInterval('P1M')));

    expect($travelled->from->format(DATE_ATOM))->toBe('2026-02-10T09:00:00+00:00')
        ->and($travelled->to->format(DATE_ATOM))->toBe('2026-03-10T09:00:00+00:00')
        ->and($travelled->offsetSeconds)->toBe(28 * 86_400)
        ->and($scenario->clock->now()->format(DATE_ATOM))->toBe('2026-03-10T09:00:00+00:00')
        // The period from 31 January closed on 28 February, and is invoiced.
        ->and(DB::table('invoices')->where('subscription_id', $scenario->subscription->id->value)->value('period_end'))->toStartWith('2026-02-28 14:00:00');
});

it('moves to an instant, and refuses to move back', function (): void {
    InvoicingScenario::start('2026-01-31 14:00:00');

    expect(travel(new TravelInTime(to: new DateTimeImmutable('2026-04-01T00:00:00Z')))->to->format(DATE_ATOM))->toBe('2026-04-01T00:00:00+00:00')
        ->and(DB::table('invoices')->count())->toBe(2)
        ->and(static fn(): Travelled => travel(new TravelInTime(to: new DateTimeImmutable('2026-03-01T00:00:00Z'))))->toThrow(RuntimeException::class, 'only moves forward');
});

it('goes back to real time on reset, and reports where it stands when asked nothing', function (): void {
    InvoicingScenario::start('2026-01-31 14:00:00');
    travel(new TravelInTime(by: new DateInterval('P3D')));

    $status = travel(new TravelInTime());
    $reset = travel(new TravelInTime(reset: true));

    expect($status->offsetSeconds)->toBe(3 * 86_400)
        ->and($status->to->format(DATE_ATOM))->toBe('2026-02-03T14:00:00+00:00')
        ->and($reset->offsetSeconds)->toBe(0)
        ->and($reset->to->format(DATE_ATOM))->toBe('2026-01-31T14:00:00+00:00');
});

it('refuses a duration it cannot read', function (): void {
    expect(Artisan::call('sim:time-travel', ['--by' => 'a month']))->toBe(2)
        ->and(Artisan::output())->toContain('ISO 8601');
});
