<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\CreditNote;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;

interface CreditNoteRepository
{
    public function add(CreditNote $note): void;

    public function forInvoice(TenantContext $tenant, Uuid $invoiceId): ?CreditNote;
}
