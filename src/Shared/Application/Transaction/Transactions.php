<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Transaction;

use Closure;

/**
 * Runs a unit of work atomically.
 *
 * Application handlers decide what belongs in one transaction; they must not
 * know which database library opens it. This is the seam: the handler says
 * "these writes commit together", the adapter says how.
 *
 * The rule it exists to serve is the one from ADR-0005 — a state change and
 * the outbox message announcing it commit together or not at all. Provisioning
 * a tenant is the first caller: an organization with no project, or a project
 * with no key, is a tenant that cannot be used and cannot be finished.
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
