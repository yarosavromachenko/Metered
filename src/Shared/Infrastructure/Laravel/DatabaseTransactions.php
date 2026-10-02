<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Laravel;

use Closure;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Transaction\Transactions;

/**
 * Uses the default connection, the same one repositories and the outbox
 * writer use.
 */
final readonly class DatabaseTransactions implements Transactions
{
    public function __construct(private DatabaseManager $db) {}

    public function run(Closure $work): mixed
    {
        return $this->db->connection()->transaction($work);
    }
}
