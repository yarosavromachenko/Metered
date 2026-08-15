<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The journal. Written to by appending transactions, read as balances
 * derived from them — there is no balance stored anywhere to disagree with it.
 */
interface Ledger
{
    public function record(LedgerTransaction $transaction): void;

    /**
     * The customer's balance on $account, on the account's natural side:
     * what they owe on receivables, what they paid on cash, what was earned
     * on revenue.
     */
    public function balance(TenantContext $tenant, Uuid $customerId, Account $account, string $currency): Money;
}
