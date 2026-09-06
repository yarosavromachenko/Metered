<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Time;

use DateInterval;
use DateTimeImmutable;
use Metered\Simulation\Application\Port\PeriodCloser;
use Metered\Simulation\Application\Port\TimeMachine;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Moves the demo's clock and lets the platform catch up: every period that
 * has ended by the new time is closed at once, the way the scheduler would
 * close it five minutes later.
 *
 * Forward only. Everything the platform wrote while the clock was ahead —
 * invoices, their numbers, the ledger — carries the later time, and moving
 * back would put "now" before them: a period would look open that is already
 * invoiced. Going back to real time is still possible, with --reset, for a
 * stack about to be wiped by demo:reset anyway.
 */
final readonly class TravelInTimeHandler
{
    public function __construct(
        private TimeMachine $machine,
        private PeriodCloser $periods,
        private ClockInterface $clock,
    ) {}

    public function handle(TravelInTime $command): Travelled
    {
        $offset = $this->machine->offsetSeconds();
        $now = $this->clock->now();
        $real = $now->sub(new DateInterval(sprintf('PT%dS', $offset)));

        if ($command->reset) {
            $this->machine->setOffset(0);

            return new Travelled($now, $real, 0);
        }

        $target = match (true) {
            $command->by instanceof DateInterval => $now->add($command->by),
            $command->to instanceof DateTimeImmutable => $command->to,
            default => null,
        };

        if (! $target instanceof DateTimeImmutable) {
            return new Travelled($now, $now, $offset);
        }

        if ($target <= $now) {
            throw new RuntimeException(sprintf(
                'The clock only moves forward: it is %s, and %s is not later. --reset goes back to real time.',
                $now->format(DATE_ATOM),
                $target->format(DATE_ATOM),
            ));
        }

        $newOffset = $target->getTimestamp() - $real->getTimestamp();
        $this->machine->setOffset($newOffset);
        $this->periods->closeDue();

        return new Travelled($now, $target, $newOffset);
    }
}
