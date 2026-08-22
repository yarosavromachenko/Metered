<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

enum DeliveryStatus: string
{
    /** Waiting for its next attempt. */
    case Pending = 'pending';

    /** A 2xx came back. */
    case Succeeded = 'succeeded';

    /** The receiver refused it for good — a 4xx other than 408 and 429 — or the address was not one to deliver to. */
    case Failed = 'failed';

    /** Every attempt failed. Waits for a person to replay it. */
    case Dead = 'dead';
}
