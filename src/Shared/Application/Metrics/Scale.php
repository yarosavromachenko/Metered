<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * Unit of a histogram's integers. Durations are recorded in nanoseconds
 * (`hrtime()`) and exported in seconds.
 */
enum Scale
{
    /** Buckets from 1 ms to 1 min. */
    case Seconds;

    /** Plain counts such as batch size; buckets in powers of about two. */
    case Count;
}
