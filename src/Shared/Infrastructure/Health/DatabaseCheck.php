<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Health;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\ReadinessCheck;

/**
 * PostgreSQL answers a query on the connection web requests use.
 *
 * A query rather than a connect: through PgBouncer the socket opens even
 * when the server behind it is gone.
 */
final readonly class DatabaseCheck implements ReadinessCheck
{
    public function __construct(
        private DatabaseManager $database,
        private ?string $connection = null,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function check(): CheckResult
    {
        $this->database->connection($this->connection)->select('select 1');

        return CheckResult::pass();
    }
}
