<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

use Metered\Invoicing\Domain\Exception\UnbalancedLedger;
use Metered\Shared\Domain\Money\Money;

/**
 * One side of a movement: an amount debited or credited to one account.
 *
 * Always positive. Which way the money moves is the direction, not the sign,
 * so a negative amount would be a second way of saying the same thing — and
 * the one that reverses a figure by accident.
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
