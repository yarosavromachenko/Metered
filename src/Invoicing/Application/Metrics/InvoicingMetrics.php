<?php

declare(strict_types=1);

namespace Metered\Invoicing\Application\Metrics;

use Metered\Shared\Application\Metrics\Counter;
use Metered\Shared\Application\Metrics\Histogram;

/**
 * The billing close's instruments, named once (docs/observability.md).
 */
final class InvoicingMetrics
{
    public static function invoicesFinalized(): Counter
    {
        return new Counter('invoices.finalized', '{invoice}', 'Invoices finalized');
    }

    public static function closeDuration(): Histogram
    {
        return Histogram::duration('billing.close.duration', 'Time to close one subscription\'s due periods');
    }
}
