# 0002. Partitioning and deduplication of usage events

- **Status:** Proposed
- **Date:** 2026-05-29

## Context

`usage_events` is the only table expected to reach tens or hundreds of millions
of rows. Two things must hold on it at once: inserts stay fast as it grows, and
the same event submitted twice is counted once.

Clients retry. A timeout after a successful write is indistinguishable from a
failure, so a well-behaved client resends — and must be able to do so safely.
Deduplication is therefore a feature of the contract, not an optimisation.

PostgreSQL adds a constraint of its own: a unique index on a partitioned table
**must include the partition key**. That single rule shapes everything below.

## Decision

Partition `usage_events` by `RANGE (occurred_at)`, one partition per day.

Deduplicate in two layers:

1. **Database:** `UNIQUE (project_id, event_id, occurred_at)`. Because the
   partition key is part of the key, this is enforceable; the insert uses
   `ON CONFLICT DO NOTHING`.
2. **Redis, in the consumer, before insert:** `SET dedup:{project}:{event_id} NX EX 604800`.
   This catches the case the database cannot.

Partitions are created ahead of time by `usage:partitions:ensure`, run on a
schedule. Retention detaches and drops old partitions rather than deleting rows.

## Consequences

Inserts touch one small partition and its indexes rather than one enormous index,
and stay predictable as the table grows. Range queries prune to the days they
need. Retention becomes a metadata operation instead of a long `DELETE`.

**The honest limitation:** the guarantee is "no duplicate within the Redis TTL
window, plus no exact duplicate of `(project_id, event_id, occurred_at)` ever".
A client that resends the same `event_id` with a *different* `occurred_at` more
than seven days later will be counted twice. This is documented in the API
reference rather than glossed over, and the client contract states that
`event_id` must be stable for a given event.

If partition creation fails, inserts fall into the default partition and lose
pruning. The scheduled command is therefore monitored, and the admin panel shows
the partition count.

## Alternatives considered

**A global unique index on `(project_id, event_id)` without partitioning.**
Rejected: the index grows without bound and insert latency degrades with it,
which is precisely the pressure this table is designed for.

**Partition by hash of `project_id`.** Good for tenant isolation, useless for
retention and for the time-range queries that dominate reads.

**Monthly partitions.** Fewer objects to manage, but each still large enough to
make index maintenance slow and retention coarse. Daily fits a seven-day
acceptance window naturally.

**Deduplicate only in Redis.** Rejected: it is a cache. An eviction or a restart
would let duplicates through, and there would be nothing behind it.

**Deduplicate only in PostgreSQL.** Accepted as the floor, but on its own it
cannot catch a differing `occurred_at`, so the Redis layer earns its place.
