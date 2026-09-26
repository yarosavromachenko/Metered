# 0006. Idempotency keys for mutating endpoints

- **Status:** Accepted
- **Date:** 2026-05-30

## Context

A client that times out cannot tell whether its request was applied. Retrying is
the only sensible behaviour, and without server support it creates a second
subscription or a second payment. Every serious billing API solves this, and
clients already expect the mechanism.

Usage ingestion is exempt: each event carries its own `event_id`, which
deduplicates at a finer grain than a request.

## Decision

Mutating management endpoints accept `Idempotency-Key`, following the IETF
idempotency-key draft.

A table `idempotency_keys`, scoped to a project, holds the key, a
`request_fingerprint` (hash of method, path and body), a status of
`in_progress` or `completed`, the stored response, and `expires_at`. The record
is claimed with `INSERT ... ON CONFLICT DO NOTHING`, which is atomic and needs no
lock.

Behaviour:

| Situation | Response |
|---|---|
| New key | Execute, store the response, return it |
| Same key, same fingerprint, completed | Stored response plus `Idempotent-Replayed: true` |
| Same key, first request still running | `409 Conflict` |
| Same key, different fingerprint | `422 Unprocessable Content` |
| Key missing where required | `400 Bad Request` |

PostgreSQL is the source of truth. Records expire after 24 hours and are removed
by a scheduled job.

## Consequences

Retrying is safe, which means clients can retry aggressively, which means fewer
support conversations about duplicate subscriptions.

`Idempotent-Replayed` is not in the draft; it is Stripe's convention. It is
included because it is genuinely useful for debugging, and its non-standard
status is recorded here rather than presented as standard.

The fingerprint check turns a client bug — reusing a key for a different payload
— into a clear `422` instead of a confusing replay of an unrelated response.

The cost is a write on the hot path of every mutating request, and a `409` window
that a client can hit by retrying too eagerly. `409` with `Retry-After` is the
correct answer there, and it is documented.

Redis is deliberately not used. A cache that evicts under memory pressure turns a
safety mechanism into a coin flip.

## Alternatives considered

**Redis with `SET NX`.** Faster and simpler, and it loses records exactly when
memory is tight — which correlates with load, which correlates with retries.

**Natural idempotency through client-supplied resource ids.** Cleaner where it
fits, and it does not fit actions that are not resource creation, such as
finalizing an invoice or triggering a payment.

**Lock the key row with `SELECT ... FOR UPDATE`.** Rejected: holding a
transaction open for the duration of request processing is how connection pools
are exhausted. The atomic insert gives the same guarantee with no held lock.


## Accepted in M1

Sixteen parallel processes claiming one key produce exactly one execution and
fifteen `409`s — real separate connections, no wrapping transaction.

One behaviour worth recording beyond the table above: a request that failed,
including one that answered `5xx`, releases its key. Replaying a failure would
be worse than useless, because nothing was carried out and the client deserves
a real second attempt.

Expiry is exact rather than eventual. A claim ignores a record past its 24
hours, so the same key after the window is a new request whatever the table
still holds, and `idempotency:purge` removes expired records every hour.
