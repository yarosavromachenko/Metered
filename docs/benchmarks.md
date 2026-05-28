# Benchmarks

> **No numbers yet.** The method is fixed here first, on purpose: targets chosen
> after seeing results are not targets. The baseline is recorded in M3, once
> ingestion exists.

A performance claim is only worth the context around it. Every result on this
page must state the hardware, the versions, the parameters and the raw output —
otherwise it is marketing.

## Method

- Load generator: k6, scenarios in `load/`.
- Runs from the same machine as the stack, which **understates** achievable
  throughput (the generator competes for CPU). This is stated rather than
  hidden, because the alternative is a lab nobody can reproduce.
- Every run: 30s warm-up, then 5 minutes measured.
- Reported per run: p50, p95, p99 latency, requests per second, error rate,
  stream lag at the end, consumer batch write duration.
- Three runs; the median is recorded. Variance goes in the notes.
- The database is seeded to a realistic size first (`sim:seed --profile=heavy`).
  Benchmarks against an empty table measure nothing.

## Scenarios

| Scenario | Shape |
|---|---|
| `ingest-steady` | Constant rate, batches of 50 events, single project |
| `ingest-burst` | 10× spike for 30 seconds — does backpressure engage, and does it recover |
| `mixed` | Ingestion plus management API reads, the realistic pattern |
| `close-periods` | 10,000 subscriptions closing at once — how long until the last invoice |

## Environment template

Every result table must be preceded by this block, filled in:

```
CPU:        
RAM:        
Disk:       
Docker:     
PHP:        
PostgreSQL: 
Redis:      
Commit:     
```

## Results

| Date | Scenario | RPS | p50 | p95 | p99 | Errors | Final lag |
|---|---|---|---|---|---|---|---|
| — | — | — | — | — | — | — | — |

## Notes

Things to check before trusting any number here:

- Was the consumer keeping up, or did the lag simply grow for five minutes?
  Throughput without a bounded lag is not throughput.
- Did any request get a `503` from backpressure? Those are not errors, but they
  must be counted separately.
- Was `pcov` or `xdebug` loaded? Either invalidates the run.
