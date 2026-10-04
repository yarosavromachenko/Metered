<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Transaction;

use Closure;

/**
 * Lets handlers commit a state change and its outbox message together
 * (ADR-0005) without depending on the database library.
 */
interface Transactions
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed;
}
