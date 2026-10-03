<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface InvoiceRepository
{
    /**
     * False when the subscription already has an invoice for the period
     * (ADR-0010).
     */
    public function add(Invoice $invoice): bool;

    /**
     * Status, number and timestamps; lines are written only by add().
     */
    public function save(Invoice $invoice): void;

    public function find(TenantContext $tenant, Uuid $id): ?Invoice;

    /**
     * SELECT ... FOR UPDATE.
     */
    public function findForUpdate(TenantContext $tenant, Uuid $id): ?Invoice;

    /**
     * Any status.
     */
    public function latestFor(TenantContext $tenant, Uuid $subscriptionId): ?Invoice;
}
