<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

enum BreakerState: string
{
    /** Deliveries go out. */
    case Closed = 'closed';

    /** Too many failed in a row; deliveries wait out the cooldown. */
    case Open = 'open';

    /** The cooldown passed; one probe is out, and its result decides. */
    case HalfOpen = 'half_open';
}
