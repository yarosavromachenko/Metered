<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Idempotency;

enum ClaimStatus
{
    /** New key: proceed. */
    case Claimed;

    /** Same key and request, finished: replay the stored response. */
    case Replayed;

    /** Same key, still in progress: the client retries later. */
    case InProgress;

    /** Same key, different request body: rejected. */
    case FingerprintMismatch;
}
