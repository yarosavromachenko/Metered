# 0002. Partitioning and deduplication of usage events

- **Status:** Accepted
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

## Accepted in M3

Built as decided: daily `RANGE (occurred_at)` partitions, the natural key as the
primary key, `ON CONFLICT DO NOTHING` against it, and a Redis layer in front.
Four things changed or were added once it existed.

**A claim records the timestamp it was taken for.** The first version set
`dedup:{project}:{event_id}` with `NX` before the write and deleted it if the
write threw. A consumer killed between the claim and the commit never gets to
delete anything, so the redelivered message found every event already claimed,
counted them as duplicates and wrote none of them. `usage:reconcile` could not
see the loss, because aggregates and events were both missing the same rows. The
claim now stores `occurred_at` and is taken with `SET NX GET`. An event under a
claim with the same timestamp goes to the database, which decides with the
primary key. Only a claim with a different timestamp counts as a duplicate, and
that is exactly the case the Redis layer is for. A test kills the write after
the claim and asserts the event arrives on redelivery.

**The default partition takes events rather than rejecting them.** The table is
created with a fortnight of partitions around the day of the migration, and
`usage:partitions:ensure` runs daily at 03:10 to keep a week ahead. If it does
not run, events land in `usage_events_default` and are no longer pruned. They
are still written, because slow ingestion is better than none. Once the default
partition holds rows for a day, PostgreSQL will not create that day's partition
on top of them, so the scheduled run stops and says so. Moving them is
`usage:partitions:ensure --rescue`, which copies the rows out and attaches them
as their day in one transaction. That is data moving under a lock, so an
operator decides when it runs, not the scheduler. Both the ingestion widget and
the command show how many rows are sitting in the default partition.

**Retention is opt-in.** `usage:partitions:ensure --prune` detaches and drops
partitions older than `USAGE_PARTITION_RETENTION_DAYS` (400 by default), and
the schedule does not pass `--prune`. Raw events are what `usage:reconcile` and
any invoice dispute are checked against (ADR-0004), so deleting them should be a
decision someone makes, not something the scheduler does.

**A third index.** The usage explorer opens on a project's newest events, and
neither the primary key nor the reading index can return those in order. Without
help, every page load sorted the whole table. `(project_id, occurred_at)` fixes
that, and costs one more index descent per inserted row. The plans before and
after, and the measured cost, are in [`query-plans.md`](../query-plans.md).

## Amended in 1.1.0: capacity

The Redis layer holds one key per event for the whole TTL, so its memory grows
with traffic, not with the number of tenants. Measured on Redis 8: a key for a
30-character `event_id` costs about 178 bytes, TTL bookkeeping included, and
the cost grows with the id. Budget 200 bytes:

```
memory = events per second × TTL (604 800 s) × 200 B
```

| Sustained rate | Keys held | Memory |
|---|---|---|
| 10 events/s | 6 million | 1.2 GB |
| 100 events/s | 60 million | 12 GB |
| 1,000 events/s | 605 million | 121 GB |
| 1,640 events/s (the benchmark's peak) | 990 million | 198 GB |

The stream lives in the same Redis and adds at most `USAGE_STREAM_MAX_LENGTH`
entries — about 300 bytes each, 0.3 GB at the default million.

Before 1.1.0 the keys shared one Redis with the cache, the queues and the
sessions, with no `maxmemory`. Running out would have taken Horizon and every
signed-in session down with ingestion. Three changes:

- **A Redis of its own.** The `usage` connection reads `REDIS_USAGE_HOST`
  (falling back to `REDIS_HOST`); compose runs it as `redis-usage`, with the
  same `appendfsync everysec` durability, `noeviction`, and
  `maxmemory ${REDIS_USAGE_MAXMEMORY:-1gb}`. `noeviction` stays: evicting a
  key would silently admit a duplicate, and evicting a stream entry would lose
  an event.
- **A full Redis is backpressure.** At `maxmemory`, XADD is refused with an
  OOM error; the endpoint answers it as it answers a deep backlog — `503` with
  `Retry-After`. Part of the batch may already be in the stream; the client
  resends all of it and the claims drop what had landed. It does not clear by
  itself: the consumer's claims are writes too, so it stalls with the endpoint,
  and nothing frees memory until keys expire. An operator raises the limit
  (runbook, "The usage Redis is running out of memory") — which is why the
  alert fires at 80%, while there is still room to do that calmly.
- **It is watched.** `usage_redis_memory_used_bytes` and
  `usage_redis_memory_limit_bytes` come from `INFO memory`, and
  `UsageRedisMemoryHigh` fires above 80% for five minutes.

The main Redis keeps no `maxmemory`. What it holds — cache entries with a TTL,
rate-limiter windows, sessions, Horizon's trimmed job history — is bounded by
users and jobs, not by event volume; the keys that grew with traffic are the
ones that moved. A limit there under `noeviction` would stop the queues, the
sessions and the API's rate limiter at once, so it comes, if it is needed,
together with a gauge and an alert of its own, and with the cache in an
instance that may evict.

The default gigabyte holds a full stream and about 3.5 million keys: some six
events per second sustained for a week, ample for the demo (twenty a second for
an hour) and for a benchmark run. A deployment sizes it from the table above.

**Past roughly a hundred events per second sustained**, holding a week of keys
in memory stops being reasonable. The two ways on are a shorter TTL, which
narrows the window described under "The honest limitation", or moving the keys
to a durable table in PostgreSQL — `(project_id, event_id)` with the timestamp,
partitioned by day like the events and dropped after the window — which costs a
write per event but turns memory into disk and survives losing Redis entirely.
The second is the right step when the traffic arrives; it is not built ahead of
it.
