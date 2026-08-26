# Query plans

For each hot query: the SQL, the `EXPLAIN (ANALYZE, BUFFERS)` output, and one
sentence on what the plan must show for the query to count as healthy. When a
plan changes after an index or schema change, the old one stays here with its
date, so the history of a regression can be seen.

## The data these plans ran against

A plan on an empty table tells you nothing: the planner picks a sequential scan
and is right to. These plans were meant to be captured on the `heavy` seed
profile, which arrives with the simulation module in M7. Until then they run
on what the M3 load runs left behind, plus a spread written straight into the
database with SQL so that the planner has some selectivity to work with:

| | Rows | Shape |
|---|---|---|
| Load-run events | 4,852,990 | One project, **one customer, one meter**, five hours of one day (`usage_events_p20260922`) |
| Spread events | 4,200,000 | The same project, 200 customers, 4 meters (one of each fold, plus a second `sum`), 700,000 a day over the six days before |
| `usage_events` total | 9,052,990 | 7 populated daily partitions out of 15, plus the default partition |
| `usage_aggregates` | 115,203 | Folded from the spread by SQL; `usage:reconcile` over the whole week reports no drift |

The spread script is in the [appendix](#appendix-the-spread). Everything
it writes has an event id or a customer reference starting with `qp-`, so it
can be deleted in one statement.

Two things about this data are worth keeping in mind. It is **one project**, so
the plans show nothing about how a tenant filter prunes other tenants' rows;
and the newest day is 4.85 million events of a single meter, which is
adversarial for any query that filters by meter while walking time backwards
(see 7c below). Both get better with the `heavy` profile, and the plans
will be captured again on it in M7.

Environment: PostgreSQL 18.6 (Alpine image), `shared_buffers = 256MB`,
`work_mem = 16MB`, the stack's own container, on the same machine as
[`benchmarks.md`](benchmarks.md). Captured 2026-07-12.

## Summary

| # | Query | Health condition | Status |
|---|---|---|---|
| 1 | Consumer bulk insert, 500 events, `ON CONFLICT DO NOTHING RETURNING` | Primary key is the conflict arbiter; cost grows with the batch, not the table | ✅ 27ms new, 11ms redelivered |
| 2 | Aggregate upsert of the returned rows | Conflict resolved on the aggregate primary key | ✅ 12ms for 320 buckets |
| 3 | Usage for one customer over a period, `GET /customers/{ref}/usage` | Index range over one customer's buckets | ✅ after `b43f5d1`, was a sequential scan |
| 4 | Invoice line build: a subscription's aggregates for its period, and what was billed for it | Index range on the aggregate key per meter; billed lines read by subscription | ✅ 0.36ms and 0.16ms |
| 5 | Outbox poll, `FOR UPDATE SKIP LOCKED` | Partial index used; published rows never read | ✅ 0.07ms under a million published rows |
| 6 | Webhook deliveries for one endpoint, newest first; the dispatcher's due deliveries | Index scan bounded by the page; partial index over pending rows | ✅ 0.2ms and 2.1ms over 200,000 deliveries |
| 7 | Usage explorer and ingestion widget | Rows read bounded by the page, not by the table | ✅ after `3960979`, with one known worst case (7c) |

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

Five hundred new events, then the same five hundred again as a redelivery, each
inside a transaction that was rolled back:

```
Insert on usage_events  (cost=0.00..6.25 rows=500 width=842) (actual time=1.223..26.544 rows=500.00 loops=1)
  Conflict Resolution: NOTHING
  Conflict Arbiter Indexes: usage_events_pkey
  Tuples Inserted: 500
  Conflicting Tuples: 0
  Buffers: shared hit=8022 read=507 dirtied=518 written=11
  ->  Values Scan on "*VALUES*"  (cost=0.00..6.25 rows=500 width=842) (actual time=0.010..1.013 rows=500.00 loops=1)
Planning Time: 4.197 ms
Execution Time: 27.353 ms
```

```
Insert on usage_events  (cost=0.00..6.25 rows=500 width=842) (actual time=11.176..11.177 rows=0.00 loops=1)
  Conflict Resolution: NOTHING
  Conflict Arbiter Indexes: usage_events_pkey
  Tuples Inserted: 0
  Conflicting Tuples: 500
  Buffers: shared hit=2506
  ->  Values Scan on "*VALUES*"  (cost=0.00..6.25 rows=500 width=842) (actual time=0.011..0.633 rows=500.00 loops=1)
Planning Time: 3.987 ms
Execution Time: 11.223 ms
```

Captured: 2026-07-12 · Rows in `usage_events`: 9,052,990 · Commit: `c6573c9`

**Reading:** healthy. The primary key is the arbiter, so deduplication is one
index probe per row whichever partition the row routes to. A redelivered batch
costs five probes a row (2,506 buffers for 500) and inserts nothing, which is
what makes a redelivery harmless (ADR-0004). A new row costs about seventeen
buffers: the heap, the primary key and the reading index.

After `3960979` added a third index, the same insert touches about 1,500 more
buffers per batch (10,100–10,900 against 8,600–9,400, six runs each), which is
one more index descent per row. The time of a 500-row insert stayed around
27ms. That is within the noise of runs like these, so the precise cost of the
index has to come from the next k6 run, not from here.

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
Insert on usage_aggregates  (cost=0.00..4.00 rows=0 width=0) (actual time=12.231..12.231 rows=0.00 loops=1)
  Conflict Resolution: UPDATE
  Conflict Arbiter Indexes: usage_aggregates_pkey
  Tuples Inserted: 0
  Conflicting Tuples: 320
  Buffers: shared hit=4118 dirtied=6 written=6
  ->  Values Scan on "*VALUES*"  (cost=0.00..4.00 rows=320 width=538) (actual time=0.009..0.476 rows=320.00 loops=1)
Planning Time: 2.016 ms
Execution Time: 12.342 ms
```

Captured: 2026-07-12 · Rows in `usage_aggregates`: 115,203 · Commit: `c6573c9`

**Reading:** healthy. Every bucket already existed, which is the expensive case
(an update is a new tuple version plus an index entry), and it resolves on the
primary key at about thirteen buffers a bucket. In practice a batch touches far
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

Healthy since `b43f5d1`. In the capture below the customer id is looked up with
an `InitPlan` so that the statement is self-contained. The application passes
the id it has already resolved through `CustomerDirectory`.

```
Sort  (cost=523.61..523.65 rows=15 width=80) (actual time=6.790..6.792 rows=4.00 loops=1)
  Sort Key: a.meter_code
  Buffers: shared hit=8 read=24
  InitPlan 1
    ->  Seq Scan on customers  (cost=0.00..8.51 rows=1 width=16) (actual time=2.575..2.593 rows=1.00 loops=1)
          Filter: ((reference)::text = 'qp-cus-042'::text)
  ->  HashAggregate  (cost=514.51..514.81 rows=15 width=80) (actual time=6.717..6.721 rows=4.00 loops=1)
        Group Key: a.meter_code, m.aggregation
        ->  Hash Join  (cost=1.53..510.94 rows=285 width=31) (actual time=4.096..6.591 rows=288.00 loops=1)
              Hash Cond: (a.meter_id = m.id)
              ->  Index Scan using usage_aggregates_pkey on usage_aggregates a  (cost=0.42..508.45 rows=285 width=43) (actual time=3.726..6.149 rows=288.00 loops=1)
                    Index Cond: ((project_id = '01a0ca27-…'::uuid) AND (customer_id = (InitPlan 1).col1)
                                 AND (bucket_start >= '2026-09-17 00:00:00+00') AND (bucket_start < '2026-09-20 00:00:00+00'))
                    Filter: (organization_id = '01a0ca27-…'::uuid)
                    Buffers: shared hit=5 read=23
              ->  Hash  (actual time=0.340..0.340 rows=5.00 loops=1)
                    ->  Seq Scan on meters m  (actual time=0.323..0.324 rows=5.00 loops=1)
Planning Time: 7.556 ms
Execution Time: 7.017 ms
```

Captured: 2026-07-12 · Rows in `usage_aggregates`: 115,203 · Commit: `b43f5d1`

**Reading:** healthy. It is a range over one customer's buckets, 288 rows and 32
buffers, and the cost grows with that customer's history rather than the
project's. The time is mostly cold reads: a warm run of the same statement took
3.3ms.

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
HashAggregate  (cost=420.13..420.46 rows=15 width=82) (actual time=0.258..0.260 rows=4.00 loops=1)
  Group Key: a.meter_id, m.aggregation
  Buffers: shared hit=12 read=13
  ->  Nested Loop  (cost=0.42..417.89 rows=224 width=25) (actual time=0.088..0.206 rows=228.00 loops=1)
        ->  Seq Scan on meters m  (cost=0.00..1.05 rows=5 width=20) (actual time=0.017..0.017 rows=5.00 loops=1)
        ->  Index Scan using usage_aggregates_pkey on usage_aggregates a  (cost=0.42..82.92 rows=45 width=21) (actual time=0.022..0.033 rows=45.60 loops=5)
              Index Cond: ((project_id = '01a0ca27-…'::uuid) AND (customer_id = '01a0cb55-…'::uuid) AND (meter_id = m.id)
                           AND (bucket_start >= '2026-08-18 09:00:00+00') AND (bucket_start < '2026-09-18 09:00:00+00'))
              Filter: (organization_id = '01a0ca27-…'::uuid)
              Index Searches: 5
Planning Time: 5.463 ms
Execution Time: 0.360 ms
```

Captured: 2026-08-19 · Rows in `usage_aggregates`: 115,204 · Commit: `4dcfdf6`

**Reading:** healthy. The planner walks the project's five meters and, for
each, takes one range of the primary key — the full key, down to the bucket —
so every row it reads is one it returns: 228 buckets, 25 buffers, a third of a
millisecond. The provisional capture grouped by `meter_id` over the key's
first three columns; joining the meters for the aggregation mode turned it
into five tighter ranges rather than one wider one.

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
GroupAggregate  (cost=0.14..8.19 rows=1 width=78) (actual time=0.065..0.071 rows=4.00 loops=1)
  Group Key: meter_id
  Buffers: shared hit=5
  ->  Index Scan using invoice_lines_subscription_id_meter_id_covers_start_index on invoice_lines
        Index Cond: ((subscription_id = '01a0d974-…'::uuid) AND (meter_id IS NOT NULL) AND (covers_start = '2026-08-18 09:00:00+00'))
        Filter: ((project_id = '01a0ca27-…'::uuid) AND (covers_end = '2026-09-18 09:00:00+00'))
Execution Time: 0.155 ms
```

**Reading:** healthy, and it stays so: the index leads with the subscription,
so the rows read are that subscription's lines for that period and meter —
the usage line and any late lines since — however many invoices the project
has. Both were captured on invoices built for two customers of the spread
in the appendix, on a plan pricing all four of its meters; the `heavy`
profile in M7 is the real test of both.

### 5. Outbox poll

```sql
SELECT id, aggregate_type, aggregate_id, type, payload, headers, occurred_at, attempts
  FROM outbox_messages
 WHERE published_at IS NULL AND attempts < $1
 ORDER BY occurred_at
 LIMIT $2
   FOR UPDATE SKIP LOCKED
```

Nothing publishes to the outbox in volume yet, so for this capture a
transaction inserted a million published messages and fifty unpublished ones,
ran `ANALYZE`, captured the plan, and rolled back.

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
| 7b | First page, `order by occurred_at desc, id desc limit 10` | 680ms, sort of 9M rows | **0.9ms**, 1.1ms at page 50 |
| 7c | `distinct meter_code` for the filter | 775ms, every partition scanned | not run: codes come from the Billing catalog |
| 7d | Widget, events in the last hour | 396ms, `received_at` in no index | **55ms** at the 100,000 cap |

#### 7b. The first page

```sql
select * from usage_events
where project_id = $1 and organization_id = $2
order by occurred_at desc, id desc
limit 11 offset 0
```

```
Limit  (cost=6.27..8.13 rows=11 width=166) (actual time=7.691..7.696 rows=11.00 loops=1)
  Buffers: shared hit=176 read=33
  ->  Incremental Sort  (cost=6.27..1533002.43 rows=9052976 width=166) (actual time=7.691..7.694 rows=11.00 loops=1)
        Sort Key: usage_events.occurred_at DESC, usage_events.id DESC
        Presorted Key: usage_events.occurred_at
        Full-sort Groups: 1  Sort Method: quicksort  Average Memory: 27kB  Peak Memory: 27kB
        ->  Merge Append  (cost=4.43..1242980.72 rows=9052976 width=166) (actual time=5.272..7.604 rows=12.00 loops=1)
              Sort Key: usage_events.occurred_at DESC
              ->  Index Scan Backward using usage_events_p20260916_project_id_occurred_at_idx on usage_events_p20260916 usage_events_2
                    (actual time=0.663..0.663 rows=1.00 loops=1)
                    Index Cond: (project_id = '01a0ca27-…'::uuid)
                    Filter: (organization_id = '01a0ca27-…'::uuid)
                    Buffers: shared hit=1 read=3
              …   the same for p20260917 … p20260921: one row each, four buffers each
              ->  Index Scan Backward using usage_events_p20260922_project_id_occurred_at_idx on usage_events_p20260922 usage_events_8
                    (actual time=0.729..3.053 rows=12.00 loops=1)
                    Index Cond: (project_id = '01a0ca27-…'::uuid)
                    Filter: (organization_id = '01a0ca27-…'::uuid)
                    Buffers: shared hit=149 read=12
              …   nine empty partitions (p20260915, p20260923 … p20260929, default): no rows, two buffers each
Planning Time: 19.330 ms
Execution Time: 8.072 ms
```

Captured: 2026-07-12 · Rows in `usage_events`: 9,052,990 · Commit: `3960979`

**Reading:** healthy. Each partition hands back its newest rows through the
index, the Merge Append interleaves them, and the incremental sort only breaks
ties on `id` within a single microsecond. The page read 12 rows from the
partition that held them and one from each of the others. This capture was
cold; warm runs of the same statement took 0.9ms, and 1.1ms at `offset 490`.
The cost does not depend on the size of the table, but it does grow with the
number of partitions, one index probe each. That is a reason for partitions to
be dropped when their retention ends, and not only for their disk.

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

#### 7c. Filtering by a meter: the known worst case

```sql
select * from usage_events
where project_id = $1 and organization_id = $2 and meter_code = $3
order by occurred_at desc, id desc
limit 11 offset 0
```

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

**Reading:** not healthy, and deliberately left alone for now. The plan walks
time backwards and discards the rows of other meters until it has a page. With
real traffic, where meters are interleaved, that means a few rows discarded per
row kept. Here the newest partition holds 4.85 million events and **none** of
them are of this meter, so all of them are discarded first. Filtering on a
meter that has been quiet recently reads every event since it was last used.

The fix, if the `heavy` profile shows the case matters, is an index on
`(project_id, meter_id, occurred_at)`. It is not added now because it would be
the fourth index on the largest table in the system and would be paid for on
every insert, and the only evidence for it so far is this adversarial data set.
Until then a time filter bounds the walk: "Last hour only" narrows it to the
partitions that hour spans.

#### 7d. The ingestion widget

```sql
select count(*) as aggregate from (
  select occurred_at from usage_events
  where project_id = $1 and occurred_at >= $2          -- an hour ago
  limit 100001
) as recent
```

```
Aggregate  (cost=4457.71..4457.72 rows=1 width=8) (actual time=54.784..54.786 rows=1.00 loops=1)
  Buffers: shared hit=2596 read=195
  ->  Limit  (cost=0.43..3207.70 rows=100001 width=8) (actual time=0.166..49.530 rows=100001.00 loops=1)
        ->  Append  (cost=0.43..138903.12 rows=4330914 width=8) (actual time=0.165..41.908 rows=100001.00 loops=1)
              ->  Index Only Scan using usage_events_p20260922_project_id_occurred_at_idx on usage_events_p20260922 usage_events_1
                    (actual time=0.164..34.3 rows=100001.00 loops=1)
                    Index Cond: ((project_id = '01a0ca27-…'::uuid) AND (occurred_at >= '2026-09-22 21:30:00+00'))
                    Heap Fetches: 2532
                    Buffers: shared hit=2596 read=195
              …   partitions outside the hour pruned; empty future partitions: no rows
Execution Time: 54.936 ms
```

Captured: 2026-07-12 · Rows in `usage_events`: 9,052,990 · Commit: `3960979`

**Reading:** healthy. The hour contains 4.3 million events, and the count stops
at 100,001 of them, reading index entries only, so a busy hour costs what the
cap costs and a quiet one less. The widget shows `100,000+` past the cap. The
heap fetches are pages written since the last vacuum, and they go away after
autovacuum.

It counts by `occurred_at` now rather than `received_at`, because that is the
column the index covers. A late event (one that occurred more than an hour
before it arrived) is therefore not in the number. The widget's label says "by
when they happened" for that reason.

---

## Appendix: the spread

Run with `psql -v ON_ERROR_STOP=1` against the stack's database. `:org` and
`:prj` are the organization and project the load runs wrote to.

```sql
BEGIN;
INSERT INTO meters (id, organization_id, project_id, code, name, aggregation, created_at)
SELECT uuidv7(), :'org', :'prj', m.code, m.code, m.agg, now()
FROM (VALUES ('qp.api-calls','sum'), ('qp.compute-seconds','sum'),
             ('qp.emails','count'), ('qp.storage-gb','max')) m(code, agg);
INSERT INTO customers (id, organization_id, project_id, reference, name, created_at)
SELECT uuidv7(), :'org', :'prj', 'qp-cus-' || lpad(i::text, 3, '0'), 'Spread ' || i, now()
FROM generate_series(1, 200) i;

CREATE TEMP TABLE qc AS SELECT row_number() OVER (ORDER BY reference) AS n, id, reference
                        FROM customers WHERE reference LIKE 'qp-cus-%';
CREATE TEMP TABLE qm AS SELECT row_number() OVER (ORDER BY code) AS n, id, code, aggregation
                        FROM meters WHERE code LIKE 'qp.%';

INSERT INTO usage_events (id, organization_id, project_id, event_id, customer_id, meter_id,
                          meter_code, customer_ref, quantity, occurred_at, received_at, properties)
SELECT uuidv7(), :'org', :'prj', 'qp-' || g.i, qc.id, qm.id, qm.code, qc.reference,
       g.q, g.t, g.t + interval '2 seconds', '{}'::jsonb
FROM (SELECT i,
             1 + (random() * 199)::int AS c,
             1 + (random() * 3)::int   AS m,
             (1 + (random() * 99)::int)::numeric AS q,
             timestamptz '2026-09-16 00:00:00+00' + (i % 6) * interval '1 day' + random() * interval '1 day' AS t
      FROM generate_series(1, 4200000) i) g
JOIN qc ON qc.n = g.c
JOIN qm ON qm.n = g.m;

INSERT INTO usage_aggregates (organization_id, project_id, customer_id, meter_id, meter_code,
                              customer_ref, bucket_start, quantity, event_count, updated_at)
SELECT :'org'::uuid, :'prj'::uuid, e.customer_id, e.meter_id, e.meter_code, e.customer_ref,
       date_trunc('hour', e.occurred_at),
       CASE qm.aggregation WHEN 'max' THEN max(e.quantity)
                           WHEN 'count' THEN count(*)
                           ELSE sum(e.quantity) END,
       count(*), now()
FROM usage_events e JOIN qm ON qm.id = e.meter_id
WHERE e.project_id = :'prj' AND e.event_id LIKE 'qp-%' AND e.occurred_at < '2026-09-22'
GROUP BY 1, 2, 3, 4, 5, 6, 7, qm.aggregation;
COMMIT;

VACUUM ANALYZE usage_events;
VACUUM ANALYZE usage_aggregates;
```

To remove it: delete from `usage_aggregates` and `usage_events` where the
reference or event id starts with `qp-`, then from `customers` and `meters`
likewise.
