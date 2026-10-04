<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

use Metered\Invoicing\Domain\Exception\UnbalancedLedger;
use Metered\Shared\Domain\Money\Money;

/**
 * The amount is always positive; the direction says debit or credit.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public Account $account,
        public Direction $direction,
        public Money $amount,
    ) {
        if (! $amount->isPositive()) {
            throw UnbalancedLedger::entryNotPositive((string) $amount);
        }
    }

    public static function debit(Account $account, Money $amount): self
    {
        return new self($account, Direction::Debit, $amount);
    }

    public static function credit(Account $account, Money $amount): self
    {
        return new self($account, Direction::Credit, $amount);
    }
}
