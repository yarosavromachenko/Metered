<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Redis;

enum ReplayOutcome: string
{
    /** Moved back to the ingestion stream. */
    case Replayed = 'replayed';

    /** No such entry. */
    case NotFound = 'not_found';

    /** Kept: malformed, would be dead-lettered again. */
    case Malformed = 'malformed';

    /** Kept: the project was deleted. */
    case ProjectGone = 'project_gone';
}
