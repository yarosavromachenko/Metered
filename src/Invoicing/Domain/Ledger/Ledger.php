<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * Append-only; balances are computed from entries, never stored.
 */
interface Ledger
{
    public function record(LedgerTransaction $transaction): void;

    /**
     * On the account's natural side.
     */
    public function balance(TenantContext $tenant, Uuid $customerId, Account $account, string $currency): Money;
}
