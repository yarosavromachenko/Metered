<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface InvoiceRepository
{
    /**
     * Stores a new draft with its lines — unless its subscription already has
     * an invoice for that period, in which case nothing is written and the
     * answer is false. A second close of one period is not an error: the
     * invoice it wanted exists (ADR-0010).
     */
    public function add(Invoice $invoice): bool;

    /**
     * Stores where the invoice is in its life: status, number and the
     * instants that go with them. Lines are written once, by add().
     */
    public function save(Invoice $invoice): void;

    public function find(TenantContext $tenant, Uuid $id): ?Invoice;

    /**
     * As find(), holding the row until the transaction ends, so that two
     * operators paying and voiding one invoice take turns.
     */
    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Invoice;

    /**
     * The subscription's invoice for its latest period, whatever its status —
     * where the next close starts from.
     */
    public function latestFor(TenantContext $tenant, Uuid $subscriptionId): ?Invoice;
}
