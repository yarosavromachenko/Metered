<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Application\Idempotency\IdempotencyStore;

/**
 * `idempotency:purge` — removes idempotency records past their retention
 * window (ADR-0006). Scheduled hourly. An expired record is already ignored
 * when its key comes back; this keeps the table the size of one window.
 */
final class PurgeIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:purge';

    protected $description = 'Remove idempotency records past their retention window';

    public function handle(IdempotencyStore $store): int
    {
        $this->components->info(sprintf('Removed %d expired idempotency record(s).', $store->purgeExpired()));

        return self::SUCCESS;
    }
}
