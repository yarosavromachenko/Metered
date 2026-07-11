<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Resolves the code an event carries to the meter it names.
 *
 * Ingestion calls this for every distinct code in a batch: an event row is
 * keyed by meter id, and the aggregate it folds into depends on the meter's
 * aggregation, so nothing can be written before this answers.
 *
 * Total by design. Any string may be asked about, including one that could
 * never be a valid code, and the answer for all of them is the same — no
 * meter answers to it. A client's typo is a rejected event with a reason, not
 * an exception unwinding a batch of five hundred.
 */
interface MeterCatalog
{
    public function find(TenantContext $tenant, string $code): ?MeterDescriptor;

    /**
     * Every code this project has defined, in alphabetical order — what a
     * screen offers to filter by without reading the events to find out.
     *
     * @return list<string>
     */
    public function codes(TenantContext $tenant): array;
}
