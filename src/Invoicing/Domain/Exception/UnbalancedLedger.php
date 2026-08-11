<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Exception;

use Metered\Shared\Domain\Exception\DomainException;

final class UnbalancedLedger extends DomainException
{
    public static function entryNotPositive(string $amount): self
    {
        return new self(sprintf('A ledger entry moves a positive amount; %s given. The direction says which way.', $amount));
    }

    public static function tooFewEntries(int $count): self
    {
        return new self(sprintf('A ledger transaction needs a debit and a credit; it was given %d entries.', $count));
    }

    public static function debitsAndCredits(string $debits, string $credits): self
    {
        return new self(sprintf('Debits of %s do not equal credits of %s.', $debits, $credits));
    }

    public static function notYetHappened(string $posting): self
    {
        return new self(sprintf('Nothing to book for %s: the invoice has not reached that state.', $posting));
    }
}
