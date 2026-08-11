<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Ledger;

enum Direction: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
