<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Application\Idempotency\IdempotencyStore;

/**
 * Scheduled hourly (ADR-0006).
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
