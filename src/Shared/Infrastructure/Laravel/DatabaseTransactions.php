<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Laravel;

use Closure;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Transaction\Transactions;

/**
 * The default connection's transaction, which is also the connection
 * repositories and the outbox writer use. Nothing here opens a connection of
 * its own — that is what keeps "the same transaction" true.
 */
final readonly class DatabaseTransactions implements Transactions
{
    public function __construct(private DatabaseManager $db) {}

    public function run(Closure $work): mixed
    {
        return $this->db->connection()->transaction($work);
    }
}
