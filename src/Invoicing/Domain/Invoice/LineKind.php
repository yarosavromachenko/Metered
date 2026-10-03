<?php

declare(strict_types=1);

namespace Metered\Invoicing\Domain\Invoice;

/**
 * `late` lines bill an earlier period than their invoice (ADR-0010).
 */
enum LineKind: string
{
    /** Not usage-based. */
    case Fixed = 'fixed';

    /** One meter's usage in the invoice's period. */
    case Usage = 'usage';

    /** Late usage of an earlier period. */
    case Late = 'late';
}
