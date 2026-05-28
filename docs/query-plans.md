# Query plans

> **Empty until M3.** Plans are captured against a database seeded with the
> `heavy` profile, because a plan on an empty table tells you nothing: the
> planner picks a sequential scan and is right to.

For each hot query: the SQL, the `EXPLAIN (ANALYZE, BUFFERS)` output, and one
sentence on what the plan must show for the query to be considered healthy. When
a plan changes after an index or schema change, the old one stays with a date, so
the history of a regression is visible.

## Queries tracked here

| # | Query | Health condition |
|---|---|---|
| 1 | Bulk insert of a 500-event batch with `ON CONFLICT DO NOTHING RETURNING` | Single partition targeted; no sequential scan of the unique index |
| 2 | Aggregate upsert for the returned rows | Index-only conflict resolution on the aggregate primary key |
| 3 | Usage for one customer, one meter, one period | Partition pruning to the days in range; index scan, not bitmap heap over everything |
| 4 | Invoice line build: aggregates for a subscription's period | Index scan on `(project_id, customer_id, meter_id, bucket_start)` |
| 5 | Outbox poll: `WHERE published_at IS NULL ORDER BY occurred_at FOR UPDATE SKIP LOCKED` | Partial index used; no scan of published rows |
| 6 | Webhook delivery list for one endpoint, newest first | Index scan, no sort node |
| 7 | Admin event explorer with filters | Partition pruning; bounded rows examined regardless of table size |

## Template

````
### N. <name>

```sql
<the query>
```

```
<EXPLAIN (ANALYZE, BUFFERS) output>
```

Captured: <date> · Rows in `usage_events`: <n> · Commit: <sha>

**Reading:** <what the plan shows, and whether it meets the health condition>
````
