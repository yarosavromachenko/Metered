<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

/**
 * What became of one dead letter asked to be replayed.
 */
enum ReplayOutcome: string
{
    /** Back in the ingestion stream, and gone from the dead-letter stream. */
    case Replayed = 'replayed';

    /** No entry with that id: never there, or already replayed. */
    case NotFound = 'not_found';

    /** Left where it is: the consumer could not read it, and would set it aside again at once. */
    case Malformed = 'malformed';
}
