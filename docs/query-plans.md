# Query plans

For each hot query: the SQL, the `EXPLAIN (ANALYZE, BUFFERS)` output, and one
sentence on what the plan must show for the query to count as healthy. When a
plan changes after an index or schema change, the old one stays here with its
date, so the history of a regression can be seen.

## The data these plans ran against

A plan on an empty table tells you nothing: the planner picks a sequential scan
and is right to. These plans ran on a clean clone after `make demo` and
`sim:seed --profile=heavy` (ADR-0016), plus three `small` tenants, so every
tenant-owned table holds other tenants' rows around the one being read:

| | Rows | Shape |
|---|---|---|
| `usage_events` | 22,204,207 | 20,196,574 in the heavy tenant (1,000 customers, 4 meters, 90 days), the rest in the showcase and the three small tenants; 90 daily partitions filled |
| `usage_aggregates` | 3,346,567 | Hourly buckets, folded by the consumer and by the seed's bulk history |
| `invoices` / `invoice_lines` | 2,193 / 5,248 | Every period that has ended, closed by `billing:close-periods` |
| `webhook_deliveries` | 23,435 | 4,068 for the endpoint read in 6 |
| `outbox_messages` | 4,687 | None unpublished |

The heavy tenant is the one queried. Its customer for 3 is the median by event
count, and the meters for 7c are its busiest (`api.requests`) and its rarest
(`storage.gb`). The capture script is in the [appendix](#appendix-the-capture-script);
each statement runs twice and the second, warm run is the one shown.

Environment: PostgreSQL 18.6 (Alpine image), `shared_buffers = 256MB`,
`work_mem = 16MB`, the stack's own container, on the same machine as
[`benchmarks.md`](benchmarks.md). Captured 2026-09-29 at `a1b2097` unless a
section says otherwise.

## Summary

| # | Query | Health condition | Status |
|---|---|---|---|
| 1 | Consumer bulk insert, 500 events, `ON CONFLICT DO NOTHING RETURNING` | Primary key is the conflict arbiter; cost grows with the batch, not the table | ✅ 17ms new, 8ms redelivered |
| 2 | Aggregate upsert of the returned rows | Conflict resolved on the aggregate primary key | ✅ 9ms for 320 buckets |
| 3 | Usage for one customer over a period, `GET /customers/{ref}/usage` | Index range over one customer's buckets | ✅ 0.5ms for a month of 744 buckets; a sequential scan before `b43f5d1` |
| 4 | Invoice line build: a subscription's aggregates for its period, and what was billed for it | Index range on the aggregate key per meter; billed lines read by subscription | ✅ 0.4ms and 0.05ms |
| 5 | Outbox poll, `FOR UPDATE SKIP LOCKED` | Partial index used; published rows never read | ✅ 0.07ms under a million published rows |
| 6 | Webhook deliveries for one endpoint, newest first; the dispatcher's due deliveries | Index scan bounded by the page; partial index over pending rows | ✅ 0.2ms and 2.1ms over 200,000 deliveries |
| 7 | Usage explorer and ingestion widget | Rows read bounded by the page, not by the table | ✅ 2.6ms for the first page, 14.7ms for the rarest meter (7c); every statement read the whole table before `3960979` |

Found along the way, and fixed before any of the plans above were captured:
`usage:reconcile` compared events and aggregates over a window that was not cut
on bucket boundaries, so the default window (the last 24 hours, cut mid-hour
almost every time) reported correct buckets as drift, and `--repair` failed on
the primary key. Fixed in `c6573c9`.

---

### 1. Consumer bulk insert

```sql
INSERT INTO usage_events
  (id, organization_id, project_id, event_id, customer_id, meter_id, meter_code, customer_ref,
   quantity, occurred_at, received_at, properties)
VALUES (...), (...), ...                                   -- 500 rows
ON CONFLICT (project_id, event_id, occurred_at) DO NOTHING
RETURNING customer_id, meter_id, meter_code, customer_ref, quantity, occurred_at
```

Five hundred new events, then the same five hundred again as a redelivery,
inside a transaction that was rolled back:

```
Insert on usage_events  (cost=0.00..6.25 rows=500 width=842) (actual time=0.196..17.249 rows=500.00 loops=1)
  Conflict Resolution: NOTHING
  Conflict Arbiter Indexes: usage_events_pkey
  Tuples Inserted: 500
  Conflicting Tuples: 0
  Buffers: shared hit=7507 read=6 dirtied=29 written=18
  ->  Values Scan on "*VALUES*"  (cost=0.00..6.25 rows=500 width=842) (actual time=0.020..1.760 rows=500.00 loops=1)
Planning Time: 2.373 ms
Execution Time: 17.334 ms
```

```
Insert on usage_events  (cost=0.00..6.25 rows=500 width=842) (actual time=8.397..8.397 rows=0.00 loops=1)
  Conflict Resolution: NOTHING
  Conflict Arbiter Indexes: usage_events_pkey
  Tuples Inserted: 0
  Conflicting Tuples: 500
  Buffers: shared hit=2009
  ->  Values Scan on "*VALUES*"  (cost=0.00..6.25 rows=500 width=842) (actual time=0.021..1.264 rows=500.00 loops=1)
Planning Time: 2.324 ms
Execution Time: 8.418 ms
```

Captured: 2026-09-29 · Rows in `usage_events`: 22,204,207 · Commit: `a1b2097`

**Reading:** healthy. The primary key is the arbiter, so deduplication is one
index probe per row whichever partition the row routes to. A redelivered batch
costs four probes a row (2,009 buffers for 500) and inserts nothing, which is
what makes a redelivery harmless (ADR-0004). A new row costs about fifteen
buffers: the heap and the table's three indexes. At 9 million rows on
2026-07-12 the same insert took 27ms and the redelivery 11ms: the cost follows
the batch, not the table.

### 2. Aggregate upsert

```sql
INSERT INTO usage_aggregates
  (organization_id, project_id, customer_id, meter_id, meter_code, customer_ref,
   bucket_start, quantity, event_count, updated_at)
VALUES (...), (...), ...                                   -- 320 buckets, all existing
ON CONFLICT (project_id, customer_id, meter_id, bucket_start) DO UPDATE SET
  quantity = usage_aggregates.quantity + excluded.quantity,
  event_count = usage_aggregates.event_count + excluded.event_count,
  updated_at = excluded.updated_at
```

```
Insert on usage_aggregates  (cost=0.00..4.00 rows=0 width=0) (actual time=8.720..8.720 rows=0.00 loops=1)
  Conflict Resolution: UPDATE
  Conflict Arbiter Indexes: usage_aggregates_pkey
  Tuples Inserted: 0
  Conflicting Tuples: 320
  Buffers: shared hit=3153 dirtied=3 written=2
  ->  Values Scan on "*VALUES*"  (cost=0.00..4.00 rows=320 width=538) (actual time=0.007..0.425 rows=320.00 loops=1)
Planning Time: 1.271 ms
Execution Time: 8.748 ms
```

Captured: 2026-09-29 · Rows in `usage_aggregates`: 3,346,567 · Commit: `a1b2097`

**Reading:** healthy. Every bucket already existed, which is the expensive case
(an update is a new tuple version plus an index entry), and it resolves on the
primary key at about ten buffers a bucket — 12ms at 115,203 aggregates on
2026-07-12, 9ms at 3.3 million now. In practice a batch touches far
fewer buckets than it has events, because events arriving together mostly fall
in the same hour.

### 3. Usage for one customer over a period

```sql
select a.meter_code, m.aggregation,
       CASE m.aggregation WHEN 'max' THEN max(a.quantity) ELSE sum(a.quantity) END::numeric(38, 6) AS quantity,
       sum(a.event_count) AS events
from usage_aggregates as a
inner join meters as m on m.id = a.meter_id
where a.project_id = $1 and a.organization_id = $2
  and a.customer_id = $3                      -- was: a.customer_ref = $3
  and a.bucket_start >= $4 and a.bucket_start < $5
group by a.meter_code, m.aggregation
order by a.meter_code
```

The capture reads one customer's previous calendar month:

```
Sort  (cost=1840.13..1840.16 rows=12 width=79) (actual time=0.416..0.416 rows=1.00 loops=1)
  Sort Key: a.meter_code
  Sort Method: quicksort  Memory: 25kB
  Buffers: shared hit=35
  ->  HashAggregate  (cost=1839.68..1839.92 rows=12 width=79) (actual time=0.412..0.412 rows=1.00 loops=1)
        Group Key: a.meter_code, m.aggregation
        Batches: 1  Memory Usage: 32kB
        Buffers: shared hit=35
        ->  Hash Join  (cost=2.02..1828.84 rows=867 width=30) (actual time=0.053..0.258 rows=744.00 loops=1)
              Hash Cond: (a.meter_id = m.id)
              Buffers: shared hit=35
              ->  Index Scan using usage_aggregates_pkey on usage_aggregates a  (cost=0.57..1824.63 rows=867 width=42) (actual time=0.022..0.125 rows=744.00 loops=1)
                    Index Cond: ((project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid) AND (customer_id = '01a0f202-ab96-7208-aecf-95412289fbf1'::uuid) AND (bucket_start >= (date_trunc('month'::text, now()) - '1 mon'::interval)) AND (bucket_start < date_trunc('month'::text, now())))
                    Filter: (organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid)
                    Index Searches: 3
                    Buffers: shared hit=34
              ->  Hash  (cost=1.20..1.20 rows=20 width=20) (actual time=0.018..0.018 rows=20.00 loops=1)
                    Buckets: 1024  Batches: 1  Memory Usage: 10kB
                    Buffers: shared hit=1
                    ->  Seq Scan on meters m  (cost=0.00..1.20 rows=20 width=20) (actual time=0.003..0.005 rows=20.00 loops=1)
                          Buffers: shared hit=1
Planning:
  Buffers: shared hit=4
Planning Time: 0.157 ms
Execution Time: 0.469 ms
```

Captured: 2026-09-29 · Rows in `usage_aggregates`: 3,346,567 · Commit: `a1b2097`

**Reading:** healthy. It is a range over one customer's buckets — 744 hours,
35 buffers — and the cost grows with that customer's history, not the
project's: the heavy tenant has 1,000 customers and the read touches one. On
2026-07-12 the same shape over 288 buckets took 3.3ms warm.

<details>
<summary>Before, 2026-07-12 at <code>c6573c9</code>: a sequential scan of every aggregate in the project</summary>

The query filtered on `customer_ref`, which every aggregate carries but no
index contains. The only usable prefix of the primary key was `project_id`, so
the planner, rightly, scanned the table.

```
Finalize GroupAggregate  (actual time=12.265..16.213 rows=4.00 loops=1)
  Buffers: shared hit=2169
  ->  Gather Merge  (actual time=12.210..16.195 rows=4.00 loops=1)
        Workers Launched: 1
        ->  Partial GroupAggregate
              ->  Sort
                    ->  Hash Join  (actual time=2.803..7.155 rows=144.00 loops=2)
                          ->  Parallel Seq Scan on usage_aggregates a  (actual time=2.770..7.089 rows=144.00 loops=2)
                                Filter: ((bucket_start >= '2026-09-17 …') AND (bucket_start < '2026-09-20 …')
                                         AND (project_id = …) AND (organization_id = …) AND ((customer_ref)::text = 'qp-cus-042'))
                                Rows Removed by Filter: 57458
                                Buffers: shared hit=2160
Execution Time: 16.442 ms
```

</details>

### 4. Invoice line build

What `DatabaseUsageTotals` issues once per period it invoices — and again for
each earlier period a late line may reach. The period's bounds are the
subscription's own instants, microseconds and all; a bucket belongs to the
period its start falls in (assumptions, 24).

```sql
select a.meter_id::text AS meter_id,
       CASE m.aggregation WHEN 'max' THEN max(a.quantity) ELSE sum(a.quantity) END::numeric(38, 6) AS quantity
from usage_aggregates as a
inner join meters as m on m.id = a.meter_id
where a.project_id = $1 and a.organization_id = $2
  and a.customer_id = $3
  and a.bucket_start >= $4 and a.bucket_start < $5      -- the period, [start, end)
group by a.meter_id, m.aggregation
```

```
HashAggregate  (cost=1819.76..1821.11 rows=60 width=82) (actual time=0.374..0.375 rows=1.00 loops=1)
  Group Key: a.meter_id, m.aggregation
  Batches: 1  Memory Usage: 32kB
  Buffers: shared hit=34
  ->  Hash Join  (cost=2.00..1811.18 rows=858 width=25) (actual time=0.054..0.238 rows=720.00 loops=1)
        Hash Cond: (a.meter_id = m.id)
        Buffers: shared hit=34
        ->  Index Scan using usage_aggregates_pkey on usage_aggregates a  (cost=0.56..1807.00 rows=858 width=21) (actual time=0.028..0.116 rows=720.00 loops=1)
              Index Cond: ((project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid) AND (customer_id = '01a0f202-a88d-7197-acc6-ff1cf049826d'::uuid) AND (bucket_start >= '2026-08-31 10:07:00+00'::timestamp with time zone) AND (bucket_start < '2026-09-30 10:07:00+00'::timestamp with time zone))
              Filter: (organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid)
              Index Searches: 2
              Buffers: shared hit=33
        ->  Hash  (cost=1.20..1.20 rows=20 width=20) (actual time=0.016..0.017 rows=20.00 loops=1)
              Buckets: 1024  Batches: 1  Memory Usage: 10kB
              Buffers: shared hit=1
              ->  Seq Scan on meters m  (cost=0.00..1.20 rows=20 width=20) (actual time=0.003..0.004 rows=20.00 loops=1)
                    Buffers: shared hit=1
Planning:
  Buffers: shared hit=4
Planning Time: 0.143 ms
Execution Time: 0.417 ms
```

Captured: 2026-09-29 · Rows in `usage_aggregates`: 3,346,567 · Commit: `a1b2097`

**Reading:** healthy. One range of the primary key, down to the bucket, for
the subscription's customer: 720 buckets for a month, 34 buffers, 0.4ms. On
2026-08-19 the same statement over 228 buckets took 0.36ms; the cost is the
period's length, not the table's.

The late sweep's other half reads what has already been billed for a period,
from the invoice lines:

```sql
select meter_id::text, sum(quantity)::numeric(38, 6)
from invoice_lines
where project_id = $1 and subscription_id = $2 and meter_id is not null
  and covers_start = $3 and covers_end = $4
group by meter_id
```

```
GroupAggregate  (cost=0.28..55.87 rows=1 width=78) (actual time=0.027..0.027 rows=1.00 loops=1)
  Group Key: meter_id
  Buffers: shared hit=3
  ->  Index Scan using invoice_lines_subscription_id_meter_id_covers_start_index on invoice_lines  (cost=0.28..55.85 rows=1 width=19) (actual time=0.023..0.024 rows=1.00 loops=1)
        Index Cond: ((subscription_id = '01a0f202-a89d-72ec-8a67-fe100e6f5539'::uuid) AND (meter_id IS NOT NULL) AND (covers_start = '2026-08-31 10:07:00+00'::timestamp with time zone))
        Filter: ((project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid) AND (covers_end = '2026-09-30 10:07:00+00'::timestamp with time zone))
        Index Searches: 1
        Buffers: shared hit=3
Planning Time: 0.057 ms
Execution Time: 0.050 ms
```

**Reading:** healthy, and it stays so: the index leads with the subscription,
so the rows read are that subscription's lines for that period and meter —
the usage line and any late lines since — however many invoices the project
has: 3 buffers among 5,248 lines.

### 5. Outbox poll

```sql
SELECT id, aggregate_type, aggregate_id, type, payload, headers, occurred_at, attempts
  FROM outbox_messages
 WHERE published_at IS NULL AND attempts < $1
 ORDER BY occurred_at
 LIMIT $2
   FOR UPDATE SKIP LOCKED
```

The heavy dataset's outbox holds 4,687 rows, all published — the poll reads one
index page and returns nothing in 0.04ms. That says nothing about volume, so
this capture stays the one of record: a transaction inserted a million published
messages and fifty unpublished ones, ran `ANALYZE`, captured the plan, and
rolled back.

```
Limit  (cost=0.14..13.05 rows=33 width=84) (actual time=0.017..0.042 rows=50.00 loops=1)
  Buffers: shared hit=53
  ->  LockRows  (cost=0.14..13.05 rows=33 width=84) (actual time=0.016..0.039 rows=50.00 loops=1)
        Buffers: shared hit=53
        ->  Index Scan using outbox_messages_unpublished_idx on outbox_messages  (cost=0.14..12.72 rows=33 width=84) (actual time=0.014..0.023 rows=50.00 loops=1)
              Filter: ((published_at IS NULL) AND (attempts < 10))
              Buffers: shared hit=3
Execution Time: 0.068 ms
```

Captured: 2026-07-12 · Rows in `outbox_messages`: 1,000,050 (rolled back) · Commit: `c6573c9`

**Reading:** healthy. The partial index only contains the backlog, so the poll
reads three index pages and none of the million published rows. It already
comes out in `occurred_at` order, so there is no sort.

### 6. Webhook deliveries for one endpoint, and what is due

The delivery log of one endpoint, newest first — what the panel and
`GET /webhook-deliveries?endpoint_id=` read:

```sql
select * from webhook_deliveries
where project_id = $1 and organization_id = $2 and endpoint_id = $3
order by created_at desc, id desc
limit 50
```

```
Limit  (cost=16.85..24.37 rows=50 width=142) (actual time=0.172..0.196 rows=50.00 loops=1)
  Buffers: shared hit=17 read=2
  ->  Incremental Sort  (cost=0.54..30093.87 rows=200002 width=142) (actual time=0.172..0.192 rows=50.00 loops=1)
        Sort Key: webhook_deliveries.created_at DESC, webhook_deliveries.id DESC
        Presorted Key: webhook_deliveries.created_at
        ->  Index Scan Backward using webhook_deliveries_endpoint_id_created_at_index on webhook_deliveries
              (actual time=0.070..0.097 rows=51.00 loops=1)
              Index Cond: (endpoint_id = '01a0d9b7-…'::uuid)
              Filter: ((project_id = …) AND (organization_id = …))
              Buffers: shared hit=8 read=2
Execution Time: 0.2 ms
```

**Reading:** healthy. The index on `(endpoint_id, created_at)` is walked
backwards and stops after 51 rows; the incremental sort only breaks ties on
`id` within one instant. The endpoint had 200,000 deliveries.

What `webhooks:dispatch` asks every ten seconds:

```sql
select * from webhook_deliveries
where status = 'pending' and next_attempt_at <= now()
order by next_attempt_at
limit 500
```

```
Limit  (cost=0.28..35.99 rows=10 width=142) (actual time=0.038..2.043 rows=500.00 loops=1)
  Buffers: shared hit=502
  ->  Index Scan using webhook_deliveries_due_index on webhook_deliveries
        (actual time=0.037..2.007 rows=500.00 loops=1)
        Index Cond: (next_attempt_at <= now())
Execution Time: 2.1 ms
```

**Reading:** healthy. The partial index holds pending deliveries only, so the
succeeded and dead ones — nearly all of the table — are never read, and the
scan is already in the order the query asks for. Its cost is the batch, not
the table.

Captured: 2026-08-26 · 200,002 rows in `webhook_deliveries`, of which 2,000
pending, 1,000 due — synthetic rows written for the capture and removed after ·
Commit: `ea620b8`

On the heavy dataset — 23,435 deliveries, 4,068 of them to the endpoint read —
the two statements take the same plans in 0.09ms and 0.16ms. The capture above,
with 200,000 deliveries to one endpoint, stays the one of record because it is
the larger.

### 7. Usage explorer and ingestion widget

One load of the explorer page runs up to three statements against
`usage_events`, and the ingestion widget on the dashboard adds a fourth, which
it repeats every five seconds while the dashboard is open. The statements are
the ones the panel actually sent, taken from the PostgreSQL statement log.

All four read the whole table at `c6573c9`. The fixes are in `df02ff3` and
`3960979`:

| | Statement | Before | After |
|---|---|---|---|
| 7a | `count(*)` for the paginator | 735ms, every partition scanned | not run: simple pagination |
| 7b | First page, `order by occurred_at desc, id desc limit 10` | 680ms, sort of 9M rows | **0.9ms** at 9M rows; **2.6ms** at 22M rows over 100 partitions |
| 7c | `distinct meter_code` for the filter | 775ms, every partition scanned | not run: codes come from the Billing catalog |
| 7d | Widget, events in the last hour | 396ms, `received_at` in no index | **55ms** at the 100,000 cap; **3.0ms** for 12,078 events |

#### 7b. The first page

```sql
select * from usage_events
where project_id = $1 and organization_id = $2
order by occurred_at desc, id desc
limit 11 offset 0
```

```
Limit  (cost=43.55..45.42 rows=11 width=154) (actual time=1.499..1.508 rows=11.00 loops=1)
  Buffers: shared hit=395
  ->  Incremental Sort  (cost=43.55..3123146.82 rows=18351607 width=154) (actual time=1.498..1.506 rows=11.00 loops=1)
        Sort Key: usage_events.occurred_at DESC, usage_events.id DESC
        Presorted Key: usage_events.occurred_at
        Full-sort Groups: 1  Sort Method: quicksort  Average Memory: 28kB  Peak Memory: 28kB
        Buffers: shared hit=395
        ->  Merge Append  (cost=42.09..2557967.86 rows=18351607 width=154) (actual time=1.464..1.485 rows=14.00 loops=1)
              Sort Key: usage_events.occurred_at DESC
              Buffers: shared hit=395
              ->  Index Scan Backward using usage_events_p20260630_project_id_occurred_at_idx on usage_events_p20260630 usage_events_1  (cost=0.12..8.14 rows=1 width=842) (actual time=0.011..0.011 rows=0.00 loops=1)
                    Index Cond: (project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid)
                    Filter: (organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid)
                    Index Searches: 1
                    Buffers: shared hit=2
                 … 99 more partitions, one index probe each …
              ->  Index Scan Backward using usage_events_p20260930_project_id_occurred_at_idx on usage_events_p20260930 usage_events_93  (cost=0.42..9899.06 rows=96321 width=154) (actual time=0.018..0.029 rows=14.00 loops=1)
                    Index Cond: (project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid)
                    Filter: (organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid)
                    Index Searches: 1
                    Buffers: shared hit=21
Planning Time: 3.207 ms
Execution Time: 2.624 ms
```

Captured: 2026-09-29 · Rows in `usage_events`: 22,204,207 · Commit: `a1b2097`

**Reading:** healthy. Each partition hands back its newest rows through the
index, the Merge Append interleaves them, and the incremental sort only breaks
ties on `id` within a single microsecond. The page read 14 rows from the
partition that held them and probed each of the other hundred once, two
buffers apiece: 395 buffers, 2.6ms. On 2026-07-12, at 9 million rows and 16
partitions, a warm run took 0.9ms. The cost does not depend on the size of the
table, but it does grow with the number of partitions, one index probe each —
a reason for partitions to be dropped when their retention ends, and not only
for their disk.

<details>
<summary>Before, 2026-07-12 at <code>c6573c9</code>: a parallel sequential scan and top-N sort of every event</summary>

```
Limit  (actual time=666.810..678.225 rows=10.00 loops=1)
  Buffers: shared hit=18131 read=293472
  ->  Gather Merge  (actual time=655.310..666.724 rows=10.00 loops=1)
        Workers Launched: 2
        ->  Sort  (actual time=640.786..640.791 rows=8.33 loops=3)
              Sort Key: usage_events.occurred_at DESC, usage_events.id DESC
              Sort Method: top-N heapsort  Memory: 29kB
              ->  Parallel Append  (actual time=12.365..406.858 rows=3017663.33 loops=3)
                    ->  Parallel Seq Scan on usage_events_p20260922 usage_events_8  (actual time=4.291..121.524 rows=1617663.33 loops=3)
                    …   and every other partition, in full
Execution Time: 679.869 ms
```

`count(*)` (7a) and `distinct meter_code` (7c) had the same shape, a Parallel
Append of sequential scans over all 9M rows, at 735ms and 775ms.

</details>

#### 7c. Filtering by a meter

```sql
select * from usage_events
where project_id = $1 and organization_id = $2 and meter_code = $3
order by occurred_at desc, id desc
limit 11 offset 0
```

Filtered by the heavy tenant's rarest meter, `storage.gb` — the case that reads
the most, since every other meter's rows are read and discarded until a page
of this one is found:

```
Limit  (cost=46.49..90.09 rows=11 width=154) (actual time=12.650..12.674 rows=11.00 loops=1)
  Buffers: shared hit=28615
  ->  Incremental Sort  (cost=46.49..1944753.68 rows=490600 width=154) (actual time=12.648..12.671 rows=11.00 loops=1)
        Sort Key: usage_events.occurred_at DESC, usage_events.id DESC
        Presorted Key: usage_events.occurred_at
        Full-sort Groups: 1  Sort Method: top-N heapsort  Average Memory: 30kB  Peak Memory: 30kB
        Pre-sorted Groups: 1  Sort Method: top-N heapsort  Average Memory: 30kB  Peak Memory: 30kB
        Buffers: shared hit=28615
        ->  Merge Append  (cost=42.09..1924517.01 rows=490600 width=154) (actual time=7.337..12.597 rows=294.00 loops=1)
              Sort Key: usage_events.occurred_at DESC
              Buffers: shared hit=28615
              ->  Index Scan Backward using usage_events_p20260630_project_id_occurred_at_idx on usage_events_p20260630 usage_events_1  (cost=0.12..8.15 rows=1 width=842) (actual time=0.023..0.023 rows=0.00 loops=1)
                    Index Cond: (project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid)
                    Filter: ((organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid) AND ((meter_code)::text = 'storage.gb'::text))
                    Index Searches: 1
                    Buffers: shared hit=2
                 … 99 more partitions, a few rows each: 7,837 read and discarded in all …
              ->  Index Scan Backward using usage_events_p20260930_project_id_occurred_at_idx on usage_events_p20260930 usage_events_93  (cost=0.42..10164.83 rows=2973 width=154) (actual time=1.298..6.513 rows=294.00 loops=1)
                    Index Cond: (project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid)
                    Filter: ((organization_id = '01a0f202-98f5-72f0-8be7-95ba61294784'::uuid) AND ((meter_code)::text = 'storage.gb'::text))
                    Rows Removed by Filter: 20061
                    Index Searches: 1
                    Buffers: shared hit=20351
Planning Time: 4.611 ms
Execution Time: 14.697 ms
```

Captured: 2026-09-29 · Rows in `usage_events`: 22,204,207 · Commit: `a1b2097`

**Reading:** healthy, with a cold cost worth knowing. Meters are interleaved in
real traffic, so the page is found in the newest partition after discarding
about 20,000 rows of other meters: 14.7ms warm, 701ms on the first run, when
those 28,615 buffers came from disk. Filtered by the busiest meter,
`api.requests`, the same statement takes 2.9ms. The index this section once
proposed, `(project_id, meter_id, occurred_at)`, is not added: the realistic
case does not need it, and it would be a fourth index paid for on every insert
into the largest table. A time filter still bounds the walk: "Last hour only"
narrows it to the partitions that hour spans.

<details>
<summary>2026-07-12 at <code>3960979</code>: 2.1 seconds on data built to be adversarial</summary>

The newest partition held 4.85 million events of one meter and none of the
meter filtered on, so all of them were read and discarded first.

```
Limit  (actual time=2108.885..2108.891 rows=11.00 loops=1)
  Buffers: shared hit=4707968 read=142705 written=11351
  ->  Incremental Sort
        ->  Merge Append
              …
              ->  Index Scan Backward using usage_events_p20260922_project_id_occurred_at_idx on usage_events_p20260922 usage_events_8
                    Filter: ((organization_id = …) AND ((meter_code)::text = 'qp.emails'::text))
                    Rows Removed by Filter: 4852990
                    Buffers: shared hit=4707934 read=142663 written=11351
Execution Time: 2109.145 ms
```

Captured: 2026-07-12 · Rows in `usage_events`: 9,052,990 · Commit: `3960979`

</details>

#### 7d. The ingestion widget

```sql
select count(*) as aggregate from (
  select occurred_at from usage_events
  where project_id = $1 and occurred_at >= $2          -- an hour ago
  limit 100001
) as recent
```

```
Aggregate  (cost=1172.36..1172.37 rows=1 width=8) (actual time=2.824..2.826 rows=1.00 loops=1)
  Buffers: shared hit=38
  ->  Limit  (cost=0.00..999.93 rows=13794 width=8) (actual time=0.032..2.352 rows=12078.00 loops=1)
        Buffers: shared hit=38
        ->  Append  (cost=0.00..999.93 rows=13794 width=8) (actual time=0.031..1.673 rows=12078.00 loops=1)
              Buffers: shared hit=38
              Subplans Removed: 92
              ->  Index Only Scan using usage_events_p20260930_project_id_occurred_at_idx on usage_events_p20260930 usage_events_1  (cost=0.42..490.48 rows=11828 width=8) (actual time=0.031..0.926 rows=12078.00 loops=1)
                    Index Cond: ((project_id = '01a0f202-9905-7014-b731-4c2e370b375a'::uuid) AND (occurred_at >= (now() - '01:00:00'::interval)))
                    Heap Fetches: 0
                    Index Searches: 1
                    Buffers: shared hit=38
              … 7 empty partitions ahead and the default one, a sequential scan of nothing each …
Planning Time: 3.084 ms
Execution Time: 2.973 ms
```

Captured: 2026-09-29 · Rows in `usage_events`: 22,204,207 · Commit: `a1b2097`

**Reading:** healthy. Partitions outside the hour are pruned when the statement
runs (92 removed), and the hour's partition is read through the index alone:
12,078 events, 38 buffers, 3ms. The count stops at 100,001, so a busy hour
costs what the cap costs — 55ms for an hour of 4.3 million events on
2026-07-12 — and a quiet one less. The widget shows `100,000+` past the cap. Heap
fetches, when there are any, are pages written since the last vacuum.

It counts by `occurred_at` now rather than `received_at`, because that is the
column the index covers. A late event (one that occurred more than an hour
before it arrived) is therefore not in the number. The widget's label says "by
when they happened" for that reason.

---

## Appendix: the capture script

Run with `psql -f` against the stack's database after the seeding described at
the top. It finds the heavy tenant, its median customer, its busiest and rarest
meters and its latest invoiced period, runs each statement twice, and marks the
warm run with `===`. Writes happen inside transactions that are rolled back.

```sql
\set ON_ERROR_STOP on
\pset pager off
\pset footer off

SELECT o.id AS org, p.id AS prj FROM organizations o JOIN projects p ON p.organization_id = o.id
 WHERE o.slug = 'heavy-bench' ORDER BY p.created_at LIMIT 1 \gset
WITH c AS (SELECT customer_id, sum(event_count) AS n FROM usage_aggregates WHERE project_id = :'prj' GROUP BY 1)
SELECT customer_id AS cus FROM c ORDER BY n OFFSET (SELECT count(*) / 2 FROM c) LIMIT 1 \gset
SELECT meter_code AS busy_meter FROM usage_aggregates WHERE project_id = :'prj' GROUP BY 1 ORDER BY sum(event_count) DESC LIMIT 1 \gset
SELECT meter_code AS rare_meter FROM usage_aggregates WHERE project_id = :'prj' GROUP BY 1 ORDER BY sum(event_count) ASC LIMIT 1 \gset
SELECT l.subscription_id AS sub, l.covers_start AS cs, l.covers_end AS ce, s.customer_id AS sub_cus
  FROM invoice_lines l JOIN subscriptions s ON s.id = l.subscription_id
 WHERE l.project_id = :'prj' AND l.meter_id IS NOT NULL ORDER BY l.covers_end DESC LIMIT 1 \gset
SELECT id AS endpoint FROM webhook_endpoints WHERE project_id = :'prj' ORDER BY created_at LIMIT 1 \gset

\echo === dataset
SELECT 'organizations' AS t, count(*) FROM organizations
UNION ALL SELECT 'usage_events', count(*) FROM usage_events
UNION ALL SELECT 'usage_events (heavy)', count(*) FROM usage_events WHERE project_id = :'prj'
UNION ALL SELECT 'usage_aggregates', count(*) FROM usage_aggregates
UNION ALL SELECT 'customers', count(*) FROM customers
UNION ALL SELECT 'subscriptions', count(*) FROM subscriptions
UNION ALL SELECT 'invoices', count(*) FROM invoices
UNION ALL SELECT 'invoice_lines', count(*) FROM invoice_lines
UNION ALL SELECT 'outbox_messages', count(*) FROM outbox_messages
UNION ALL SELECT 'outbox_messages unpublished', count(*) FROM outbox_messages WHERE published_at IS NULL
UNION ALL SELECT 'webhook_deliveries', count(*) FROM webhook_deliveries
UNION ALL SELECT 'webhook_deliveries (endpoint)', count(*) FROM webhook_deliveries WHERE endpoint_id = :'endpoint'
UNION ALL SELECT 'webhook_deliveries pending', count(*) FROM webhook_deliveries WHERE status = 'pending';
SELECT count(*) AS partitions_filled FROM pg_class c JOIN pg_inherits i ON i.inhrelid = c.oid
 WHERE i.inhparent = 'usage_events'::regclass AND c.reltuples > 0;
\echo heavy customer :cus busy :busy_meter rare :rare_meter subscription :sub

-- 1. Consumer bulk insert: 500 new events, then the same 500 as a redelivery.
SELECT 'INSERT INTO usage_events (id, organization_id, project_id, event_id, customer_id, meter_id, meter_code, customer_ref, quantity, occurred_at, received_at, properties) VALUES '
    || string_agg(format('(uuidv7(), %L, %L, %L, %L, %L, %L, %L, %s, %L, %L, ''{}''::jsonb)',
         :'org', :'prj', 'qp-bench-' || g.i, c.id, m.id, m.code, c.reference, 1 + g.i % 7,
         now() - (g.i || ' seconds')::interval, now()), ', ')
    || ' ON CONFLICT (project_id, event_id, occurred_at) DO NOTHING RETURNING customer_id, meter_id, meter_code, customer_ref, quantity, occurred_at' AS ins
  FROM generate_series(1, 500) g(i)
  CROSS JOIN LATERAL (SELECT id, reference FROM customers WHERE project_id = :'prj' ORDER BY id OFFSET (g.i * 7) % 1000 LIMIT 1) c
  CROSS JOIN LATERAL (SELECT id, code FROM meters WHERE project_id = :'prj' ORDER BY code OFFSET g.i % (SELECT count(*) FROM meters WHERE project_id = :'prj') LIMIT 1) m \gset
BEGIN; EXPLAIN (ANALYZE, BUFFERS) :ins; EXPLAIN (ANALYZE, BUFFERS) :ins; ROLLBACK;
\echo === 1. bulk insert, new then redelivered
BEGIN; EXPLAIN (ANALYZE, BUFFERS) :ins; EXPLAIN (ANALYZE, BUFFERS) :ins; ROLLBACK;

-- 2. Aggregate upsert of 320 buckets that all exist.
SELECT 'INSERT INTO usage_aggregates (organization_id, project_id, customer_id, meter_id, meter_code, customer_ref, bucket_start, quantity, event_count, updated_at) VALUES '
    || string_agg(format('(%L, %L, %L, %L, %L, %L, %L, 1, 1, now())', a.organization_id, a.project_id, a.customer_id, a.meter_id, a.meter_code, a.customer_ref, a.bucket_start), ', ')
    || ' ON CONFLICT (project_id, customer_id, meter_id, bucket_start) DO UPDATE SET quantity = usage_aggregates.quantity + excluded.quantity, event_count = usage_aggregates.event_count + excluded.event_count, updated_at = excluded.updated_at' AS ups
  FROM (SELECT * FROM usage_aggregates WHERE project_id = :'prj' ORDER BY bucket_start DESC LIMIT 320) a \gset
BEGIN; EXPLAIN (ANALYZE, BUFFERS) :ups; ROLLBACK;
\echo === 2. aggregate upsert
BEGIN; EXPLAIN (ANALYZE, BUFFERS) :ups; ROLLBACK;

\set q3 'select a.meter_code, m.aggregation, CASE m.aggregation WHEN ''max'' THEN max(a.quantity) ELSE sum(a.quantity) END::numeric(38, 6) AS quantity, sum(a.event_count) AS events from usage_aggregates as a inner join meters as m on m.id = a.meter_id where a.project_id = ' :'prj' ' and a.organization_id = ' :'org' ' and a.customer_id = ' :'cus' ' and a.bucket_start >= date_trunc(''month'', now()) - interval ''1 month'' and a.bucket_start < date_trunc(''month'', now()) group by a.meter_code, m.aggregation order by a.meter_code'
EXPLAIN (ANALYZE, BUFFERS) :q3;
\echo === 3. usage for one customer over a month
EXPLAIN (ANALYZE, BUFFERS) :q3;

\set q4a 'select a.meter_id::text AS meter_id, CASE m.aggregation WHEN ''max'' THEN max(a.quantity) ELSE sum(a.quantity) END::numeric(38, 6) AS quantity from usage_aggregates as a inner join meters as m on m.id = a.meter_id where a.project_id = ' :'prj' ' and a.organization_id = ' :'org' ' and a.customer_id = ' :'sub_cus' ' and a.bucket_start >= ' :'cs' ' and a.bucket_start < ' :'ce' ' group by a.meter_id, m.aggregation'
\set q4b 'select meter_id::text, sum(quantity)::numeric(38, 6) from invoice_lines where project_id = ' :'prj' ' and subscription_id = ' :'sub' ' and meter_id is not null and covers_start = ' :'cs' ' and covers_end = ' :'ce' ' group by meter_id'
EXPLAIN (ANALYZE, BUFFERS) :q4a; EXPLAIN (ANALYZE, BUFFERS) :q4b;
\echo === 4. invoice line build: aggregates, then what was billed
EXPLAIN (ANALYZE, BUFFERS) :q4a; EXPLAIN (ANALYZE, BUFFERS) :q4b;

\set q5 'SELECT id, aggregate_type, aggregate_id, type, payload, headers, occurred_at, attempts FROM outbox_messages WHERE published_at IS NULL AND attempts < 10 ORDER BY occurred_at LIMIT 100 FOR UPDATE SKIP LOCKED'
EXPLAIN (ANALYZE, BUFFERS) :q5;
\echo === 5. outbox poll
EXPLAIN (ANALYZE, BUFFERS) :q5;

\set q6a 'select * from webhook_deliveries where project_id = ' :'prj' ' and organization_id = ' :'org' ' and endpoint_id = ' :'endpoint' ' order by created_at desc, id desc limit 50'
\set q6b 'select * from webhook_deliveries where status = ''pending'' and next_attempt_at <= now() order by next_attempt_at limit 500'
EXPLAIN (ANALYZE, BUFFERS) :q6a; EXPLAIN (ANALYZE, BUFFERS) :q6b;
\echo === 6. deliveries of one endpoint, then what is due
EXPLAIN (ANALYZE, BUFFERS) :q6a; EXPLAIN (ANALYZE, BUFFERS) :q6b;

\set q7b 'select * from usage_events where project_id = ' :'prj' ' and organization_id = ' :'org' ' order by occurred_at desc, id desc limit 11 offset 0'
\set q7cbusy 'select * from usage_events where project_id = ' :'prj' ' and organization_id = ' :'org' ' and meter_code = ' :'busy_meter' ' order by occurred_at desc, id desc limit 11 offset 0'
\set q7crare 'select * from usage_events where project_id = ' :'prj' ' and organization_id = ' :'org' ' and meter_code = ' :'rare_meter' ' order by occurred_at desc, id desc limit 11 offset 0'
\set q7d 'select count(*) as aggregate from (select occurred_at from usage_events where project_id = ' :'prj' ' and occurred_at >= now() - interval ''1 hour'' limit 100001) as recent'
EXPLAIN (ANALYZE, BUFFERS) :q7b; EXPLAIN (ANALYZE, BUFFERS) :q7cbusy; EXPLAIN (ANALYZE, BUFFERS) :q7crare; EXPLAIN (ANALYZE, BUFFERS) :q7d;
\echo === 7b. explorer first page
EXPLAIN (ANALYZE, BUFFERS) :q7b;
\echo === 7c. filtered by the busiest meter
EXPLAIN (ANALYZE, BUFFERS) :q7cbusy;
\echo === 7c. filtered by the rarest meter
EXPLAIN (ANALYZE, BUFFERS) :q7crare;
\echo === 7d. ingestion widget
EXPLAIN (ANALYZE, BUFFERS) :q7d;
```
