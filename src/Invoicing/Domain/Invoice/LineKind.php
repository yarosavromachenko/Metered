<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * What a line bills.
 *
 * `late` is its own kind rather than a usage line with a flag, because it
 * bills a different period than the invoice it sits on — the one thing a
 * reader must not miss (ADR-0010).
 */
enum LineKind: string
{
    /** A charge for the period that does not depend on usage. */
    case Fixed = 'fixed';

    /** Usage of one meter during the invoice's own period. */
    case Usage = 'usage';

    /** Usage that reached an earlier period after its invoice was built. */
    case Late = 'late';
}
