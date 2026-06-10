<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Idempotency;

/**
 * What happened when a request tried to claim an idempotency key.
 */
enum ClaimStatus
{
    /** Nobody had used this key: the request may proceed. */
    case Claimed;

    /** The same key and the same request, already finished: replay the response. */
    case Replayed;

    /** The same key, still running somewhere else: tell the client to retry. */
    case InProgress;

    /** The same key, a different request: a client bug, not a retry. */
    case FingerprintMismatch;
}
