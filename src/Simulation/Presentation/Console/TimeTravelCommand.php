<?php

declare(strict_types=1);

namespace Metered\Simulation\Presentation\Console;

use DateInterval;
use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use Metered\Simulation\Application\Time\TravelInTime;
use Metered\Simulation\Application\Time\TravelInTimeHandler;
use RuntimeException;

final class TimeTravelCommand extends Command
{
    protected $signature = 'sim:time-travel
        {--by= : Move forward by an ISO 8601 duration, such as P1M, P7D or PT6H}
        {--to= : Move forward to an instant, RFC 3339}
        {--reset : Go back to real time}';

    protected $description = 'Move the demo stack\'s clock forward and close the periods that end on the way';

    public function handle(TravelInTimeHandler $handler): int
    {
        try {
            $by = $this->option('by');
            $to = $this->option('to');

            $travelled = $handler->handle(new TravelInTime(
                by: is_string($by) && $by !== '' ? new DateInterval($by) : null,
                to: is_string($to) && $to !== '' ? new DateTimeImmutable($to) : null,
                reset: $this->option('reset') === true,
            ));
        } catch (RuntimeException $refused) {
            $this->components->error($refused->getMessage());

            return self::INVALID;
        } catch (Exception) {
            $this->components->error('--by takes an ISO 8601 duration (P1M, P7D, PT6H) and --to an instant (2026-12-01T00:00:00Z).');

            return self::INVALID;
        }

        if ($travelled->from->format('U.u') === $travelled->to->format('U.u')) {
            $this->components->info(sprintf('It is %s, %s.', $travelled->to->format('Y-m-d H:i:s \U\T\C'), $this->offset($travelled->offsetSeconds)));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'The clock moved from %s to %s, %s.%s',
            $travelled->from->format('Y-m-d H:i'),
            $travelled->to->format('Y-m-d H:i \U\T\C'),
            $this->offset($travelled->offsetSeconds),
            $travelled->offsetSeconds === 0 ? '' : ' Every period that ended on the way is closed.',
        ));

        return self::SUCCESS;
    }

    private function offset(int $seconds): string
    {
        return match (true) {
            $seconds === 0 => 'real time',
            $seconds < 86_400 => sprintf('%s hour(s) ahead of real time', rtrim(rtrim(number_format($seconds / 3_600, 1), '0'), '.')),
            default => sprintf('%s day(s) ahead of real time', rtrim(rtrim(number_format($seconds / 86_400, 1), '0'), '.')),
        };
    }
}
