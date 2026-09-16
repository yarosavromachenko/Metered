<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Metrics;

/**
 * What a histogram's recorded integers are, and so how they are exported and
 * which buckets they fall into.
 *
 * Integers throughout, because the application layers hold no floats: a
 * duration is recorded in nanoseconds, as `hrtime()` gives it, and becomes
 * seconds only on its way out.
 */
enum Scale
{
    /** Recorded in nanoseconds, exported in seconds, bucketed from a millisecond to a minute. */
    case Seconds;

    /** Recorded and exported as it is — a batch's size — bucketed in powers of about two. */
    case Count;
}
