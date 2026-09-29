# Benchmarks

A performance claim is only worth the context around it. Every result on this
page must state the hardware, the versions, the parameters and the raw output —
otherwise it is marketing.

## Method

- Load generator: k6, profiles in [`k6/`](../k6).
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
| `mixed` | Ingestion plus management API reads, the realistic pattern — [`k6/mixed.js`](../k6/mixed.js), `make load SCENARIO=mixed` against a seeded tenant |
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
| 2026-09-29 | `ingest-steady`, tracing on, heavy dataset | 41.3 | 32.3 ms | 35.6 ms | 38.6 ms | 0 | 0 |
| 2026-09-29 | `mixed`: ingestion / dashboard reads | 52.0 | 15.0 / 11.5 ms | 17.0 / 20.8 ms | 19.7 / 25.0 ms | 0 | 0 |
| 2026-09-21 | `ingest-steady`, tracing on | 41.4 | 31.7 ms | 34.9 ms | 37.0 ms | 0 | 49–100 |
| 2026-09-21 | `ingest-steady`, tracing off | 41.4 | 31.4 ms | 34.6 ms | 36.6 ms | 0 | — |
| 2026-07-11 | `ingest-steady` | 40.7 | 31.6 ms | 36.1 ms | 570 ms | 0 | 0 |

`ingest-burst` and `close-periods` have no script.

### 3. `ingest-steady` — 2026-09-29, the release

```
CPU:        AMD Ryzen 7 7435HS, 16 threads
RAM:        15 GiB
Disk:       Micron MTFDKCD512QFM NVMe SSD, 477 GB
Docker:     29.8.1, compose 5.5.1, kernel 7.0.0-34-generic
PHP:        8.4.26, FrankenPHP 1.12.7, Caddy 2.11.4, Octane 2.19.1, Laravel 13.32.0
            OpenTelemetry PHP SDK 1.15.0
PostgreSQL: 18.6 (behind PgBouncer 1.23.1, transaction pooling)
Redis:      8.10.1
Commit:     a1b2097
```

The final numbers, measured the way the method above asks and nothing else
running on the machine: a clean clone, `make demo`, the showcase's traffic
generator stopped, then `sim:seed --profile=heavy` — 1,000 customers and
19.9 million events of history plus a live day through the API, 17 minutes —
and three `small` tenants. `usage_events` held 22,204,207 rows before the
series. Parameters as in run 1: `k6/ingest.js`, batches of 50 events, one
customer and one meter of the heavy tenant, 30s warm-up at 2 VUs, then 5
minutes at a constant 40 requests a second,
`API_KEY_RATE_LIMIT_PER_MINUTE=1000000`, tracing on as `make demo` runs it.

| Run | p50 | p90 | p95 | p99 | max | Requests | RPS | Errors | Shed (503) |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 32.05 | 34.72 | 35.72 | 38.37 | 68.84 | 13,848 | 41.34 | 0 | 0 |
| 2 | 32.29 | 34.83 | 36.00 | 40.52 | 64.35 | 13,804 | 41.20 | 0 | 0 |
| 3 | 32.28 | 34.70 | 35.61 | 38.63 | 79.34 | 13,850 | 41.34 | 0 | 0 |

After the series the consumer group had nothing pending and no lag. Over the
last five minutes, from Prometheus: batch write p50 14 ms, p99 42 ms, about 40
events a batch.

Raw output of run 3, the median:

```
     ✓ answered as the API promises
     ✓ never a server error
     batch_size.....................: min=50      med=50      p(90)=50       p(95)=50       p(99)=50       max=50
   ✓ checks.........................: 100.00% 27700 out of 27700
     data_sent......................: 141 MB  422 kB/s
     events_accepted................: 692500  2066.852393/s
     http_req_duration..............: min=29.01ms med=32.16ms p(90)=34.6ms   p(95)=35.51ms  p(99)=38.59ms  max=79.34ms
       { expected_response:true }...: min=29.01ms med=32.16ms p(90)=34.6ms   p(95)=35.51ms  p(99)=38.59ms  max=79.34ms
     ✓ { phase:plateau }............: min=29.01ms med=32.28ms p(90)=34.7ms   p(95)=35.61ms  p(99)=38.63ms  max=79.34ms
     http_req_failed................: 0.00%   0 out of 13850
     http_req_waiting...............: min=28.86ms med=32ms    p(90)=34.43ms  p(95)=35.35ms  p(99)=38.38ms  max=79.24ms
     http_reqs......................: 13850   41.337048/s
     iterations.....................: 13850   41.337048/s
     vus............................: 1       min=0              max=2
```

**Reading.** Both thresholds hold in all three runs. Against the 2026-09-27
series at 17 million rows, the median p99 moved from 37.0 to 38.6 ms and p50
from 31.7 to 32.3 ms, with 22 million rows and four other tenants in the
tables: the hot path is Redis and serialisation, and the table under it is not
in the request. That is the claim ADR-0003 makes, now held on the dataset it
was meant for.

### `mixed` — 2026-09-29

Same environment and dataset, straight after the series above. `k6/mixed.js`:
ingestion at 30 requests a second in batches of 20, while a dashboard reads at
10 iterations a second — one customer's usage this month (spread over all
1,000 customers), the latest invoices, the catalog — for 3 minutes, with the
heavy tenant's key.

```
     ✓ ingestion answered as promised
     █ customer usage this month
       ✓ usage read
     █ latest invoices
       ✓ invoices read
     █ catalog
       ✓ catalog read
   ✓ checks.........................: 100.00% 9364 out of 9364
     data_sent......................: 19 MB   105 kB/s
     group_duration.................: min=4.09ms  med=11.6ms  p(90)=17.6ms   p(95)=20.99ms  p(99)=25.19ms max=183.62ms
     http_req_duration..............: min=4ms     med=14.51ms p(90)=16.46ms  p(95)=18.01ms  p(99)=23.54ms max=183.48ms
       { expected_response:true }...: min=4ms     med=14.51ms p(90)=16.46ms  p(95)=18.01ms  p(99)=23.54ms max=183.48ms
     ✓ { kind:ingest }..............: min=13.03ms med=15ms    p(90)=16.33ms  p(95)=16.99ms  p(99)=19.7ms  max=51.83ms
     ✓ { kind:read }................: min=4ms     med=11.48ms p(90)=17.42ms  p(95)=20.81ms  p(99)=25ms    max=183.48ms
     http_req_failed................: 0.00%   0 out of 9364
     http_req_waiting...............: min=3.94ms  med=14.41ms p(90)=16.35ms  p(95)=17.91ms  p(99)=23.45ms max=183.41ms
     http_reqs......................: 9364    52.015187/s
     iterations.....................: 7202    40.0057/s
     vus............................: 0       min=0            max=0
```

**Reading.** Reads that go to PostgreSQL stay quick while the consumer writes
into the same tables — p99 25 ms, the usage read an index range over one
customer's aggregates (see [`query-plans.md`](query-plans.md), 3) — and
ingestion stays where it was, p99 19.7 ms for batches of 20. One run, not three:
this scenario asks whether the two interfere, and at these rates they do not.

### 2. `ingest-steady` — 2026-09-21

```
CPU:        AMD Ryzen 7 7435HS, 16 threads
RAM:        15 GiB
Disk:       Micron MTFDKCD512QFM NVMe SSD, 477 GB
Docker:     29.8.1, compose 5.5.1, kernel 7.0.0-34-generic
PHP:        8.4.25, FrankenPHP 1.12.7, Caddy 2.11.4, Octane 2.19.1, Laravel 13.31.0
            OpenTelemetry PHP SDK 1.15.0
PostgreSQL: 18.6 (behind PgBouncer 1.23.1, transaction pooling)
Redis:      8.10.1
Commit:     eed9e7c
```

Parameters as in run 1: `k6/ingest.js`, batches of 50 events, one project, one
meter, one customer, 30s warm-up at 2 VUs, then 5 minutes at a constant 40
requests a second, `API_KEY_RATE_LIMIT_PER_MINUTE=1000000`. `usage_events`
held 17,450,489 rows before the series. Three runs with tracing off
(`OTEL_SDK_DISABLED=true`) and three with tracing on, exporting to the
collector as `make demo` runs it — every span recorded, none sampled away, so
the difference between the two is the SDK's full cost. Latency is the
`{ phase:plateau }` series, in milliseconds.

| Tracing | Run | p50 | p90 | p95 | p99 | max | Requests | RPS | Errors | Shed (503) |
|---|---|---|---|---|---|---|---|---|---|---|
| off | 1 | 31.41 | 33.66 | 34.55 | 36.60 | 133.56 | 13,868 | 41.40 | 0 | 0 |
| off | 2 | 31.25 | 33.49 | 34.34 | 36.64 | 147.34 | 13,888 | 41.46 | 0 | 0 |
| off | 3 | 31.19 | 33.39 | 34.20 | 36.30 | 155.26 | 13,896 | 41.48 | 0 | 0 |
| on | 1 | 31.71 | 33.95 | 34.85 | 36.99 | 137.72 | 13,883 | 41.44 | 0 | 0 |
| on | 2 | 31.37 | 33.67 | 34.55 | 36.69 | 164.80 | 13,871 | 41.40 | 0 | 0 |
| on | 3 | 31.40 | 33.59 | 34.52 | 37.25 | 162.22 | 13,892 | 41.46 | 0 | 0 |

With tracing on, the application's own metrics were read from Prometheus
20 seconds after each run: `sum(ingest_requests_total)` equalled k6's request
count in all three (13,883, 13,871, 13,892). The consumer over each run's last
five minutes (histogram quantiles are interpolated within buckets): batch
write p50 8–10 ms, p99 38–39 ms; 34–36 events a batch on average; the stream's
pending count peaked at 149–250 and stood at 49–100 when read, so it drained
as fast as it filled.

Raw output of tracing-on run 1, the median:

```
     ✓ answered as the API promises
     ✓ never a server error
     batch_size.....................: min=50      med=50      p(90)=50       p(95)=50       p(99)=50       max=50
   ✓ checks.........................: 100.00% 27766 out of 27766
     data_received..................: 5.2 MB  16 kB/s
     data_sent......................: 148 MB  442 kB/s
     events_accepted................: 694150  2071.871986/s
     http_req_blocked...............: min=1.47µs  med=5.66µs  p(90)=6.72µs   p(95)=7.48µs   p(99)=13.91µs  max=7.3ms
     http_req_connecting............: min=0s      med=0s      p(90)=0s       p(95)=0s       p(99)=0s       max=526.12µs
     http_req_duration..............: min=28.16ms med=31.57ms p(90)=33.87ms  p(95)=34.8ms   p(99)=36.99ms  max=137.72ms
       { expected_response:true }...: min=28.16ms med=31.57ms p(90)=33.87ms  p(95)=34.8ms   p(99)=36.99ms  max=137.72ms
     ✓ { phase:plateau }............: min=29ms    med=31.71ms p(90)=33.95ms  p(95)=34.85ms  p(99)=36.99ms  max=137.72ms
     http_req_failed................: 0.00%   0 out of 13883
     http_req_waiting...............: min=28.02ms med=31.41ms p(90)=33.7ms   p(95)=34.62ms  p(99)=36.81ms  max=137.59ms
     http_reqs......................: 13883   41.43744/s
     iterations.....................: 13883   41.43744/s
     vus............................: 1       min=0              max=2
```

**Reading.** Both thresholds hold, `p(95)<50` and `p(99)<150`, in all six
runs. The SDK's cost is at the level of the noise: the medians of the two
sets differ by 0.15 ms at p50, 0.2 ms at p95 and 0.4 ms at p99, about as much
as runs of the same set differ from each other (up to 0.56 ms at p99).

### 1. `ingest-steady` — 2026-07-11

```
CPU:        AMD Ryzen 7 7435HS, 16 threads
RAM:        15 GiB
Disk:       Micron MTFDKCD512QFM NVMe SSD, 477 GB
Docker:     29.8.1, compose 5.5.1, kernel 7.0.0-31-generic
PHP:        8.4.23, FrankenPHP 1.12.4, Caddy 2.11.4, Octane 2.17.5, Laravel 13.19.0
PostgreSQL: 18.4 (behind PgBouncer 1.23.1, transaction pooling)
Redis:      8.10.1
Commit:     9351829
```

`usage_events` held 4.2 million rows at the start of the third run.

| Run | p50 | p90 | p95 | p99 | max | Requests | RPS | Errors | Shed (503) | Final lag |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 31.61 ms | 34.22 ms | 35.71 ms | 586.05 ms | 783.54 ms | 13,636 | 40.70 | 0 | 0 | 0 |
| 2 | 31.64 ms | 34.35 ms | 36.09 ms | 570.30 ms | 777.51 ms | 13,614 | 40.62 | 0 | 0 | 0 |
| 3 | 31.70 ms | 34.41 ms | 36.09 ms | 559.11 ms | 762.11 ms | 13,654 | 40.76 | 0 | 0 | 0 |

This run failed `p(99)<150`: about 2% of requests took 500–780 ms while the
median held at 31 ms, in bursts roughly every 5.3 seconds. The shape was the
same from two independent clients (k6 inside the Docker network, a Python
client on the host), and Octane's own per-request timing agreed.

Ruled out then, each by re-running with the variable changed: the consumer,
the outbox relay and Horizon (tail unchanged with all three stopped); Redis
(`redis-cli --latency` peaked at 23 ms; disabling RDB snapshots changed
nothing); Octane worker recycling (`--max-requests=100000`); PHP garbage
collection (`zend.enable_gc=0`); debug mode; the machine (load average near
2.1 of 16 threads). The ingestion handler itself, timed from the inside, never
took more than 21.7 ms, and a route that does nothing had a p99 of 5.2 ms at
the same rate, which put the tail between the two.

## What the 2026-07-11 tail was

Tracing, left on by mistake and exporting to a collector that did not exist.

At commit `9351829` the flag was read as `env('OTEL_SDK_DISABLED', 'true') !==
'true'`, which never matched (fixed in `74e1388`), so every process traced. The
batch span processor has no thread of its own: it exports when a span ends
after its five-second delay has passed, synchronously, inside whichever request
ended that span. With the collector's host name unresolvable, the OTLP
transport retries three times with a backoff of 100, 200 and 400 ms, each
jittered down to half. One export, timed inside the app container, took
511–573 ms. Every Octane worker paid that once per five seconds of its own,
which is the 500–780 ms tail in bursts about 5.3 seconds apart.

Confirmed by putting it back: with the export after the response switched off,
the provider shared across a worker's requests and the collector's
host name unresolvable, the same profile gave a p99 of 584.43 ms and a maximum
of 791.13 ms against the recorded 570.30 ms and 777.51 ms.

Two things in the code now keep this off the request path:

- **The export happens after the response.** Every Octane request, once
  answered, flushes what the worker holds if a second has passed since the
  last flush, so the five-second export inside a request no longer comes due.
  With the collector's host name unresolvable, the same profile gave a p99 of
  38.6 ms: a worker still spends about half a second failing each export, but
  after the response. The odd request still pays it (the run's maximum was
  654 ms), when a span ends inside a request after five seconds without a
  flush.
- **One tracer and one meter provider per worker.** Octane serves each request
  from a clone of the booted application and drops whatever the clone
  resolved first. Resolved lazily, the providers were rebuilt for every
  request: each request exported its one span on its own, and counters
  restarted from zero, so Prometheus saw about 5,900 of 13,800 ingestion
  requests. They are now built at boot and kept for the worker's life.

Counting the same run's requests in Prometheus found one more gap, on the
query side. Every worker's counters are a series of their own, and `rate()`
and `increase()` measure growth between a series' samples, so whatever a
series held when first scraped was never counted. Octane replaced a worker
after its default 500 requests, and FrankenPHP sends most requests to the
first idle workers, so a busy worker lived about thirty seconds and was first
scraped at around 200: 31 series in one run, 2,460 requests invisible to
`increase()` although every one of them reached Prometheus. A counter a
process increments once — a webhook delivery to a quiet endpoint — was
invisible altogether: in the hour before the fix, 300 delivery series showed
an `increase()` of 4.2. Prometheus now records a zero at each series' start
(`created-timestamp-zero-ingestion`, reading the start time the collector
already exposes), and a worker is replaced after 10,000 requests; the next
run had 7 series, whose totals came to 13,679 of k6's 13,866 — the rest being
the last seconds each worker had not yet exported when the run stopped
(ADR-0019).

Still true of both runs: the median is dominated by validating fifty nested
events, not by the stream write, which is the opposite of where the design put
its attention — ADR-0003 argues the endpoint is cheap because it only appends
to Redis, and appending to Redis is about a millisecond of it. A client sending
batches of one saw a 7.6 ms median on 2026-07-11, so per-event cost is roughly
0.6 ms of framework time, which is the number worth attacking.

## Notes

Things to check before trusting any number here:

- Was the consumer keeping up, or did the lag simply grow for five minutes?
  Throughput without a bounded lag is not throughput.
- Did any request get a `503` from backpressure? Those are not errors, but they
  must be counted separately.
- Was `pcov` or `xdebug` loaded? Either invalidates the run.

Deviations from the method, stated rather than buried:

- **`pcov` was loaded** in both runs. It ships in the development image and the
  stack under test is the one `make up` builds, so the runs were made with the
  extension present but not collecting. No run has been made without it.
- **The `protobuf` extension is not installed**, so with tracing on the OTLP
  payloads are serialised by the pure-PHP `google/protobuf` library. The
  extension would make the tracing-on figures cheaper, not dearer.
- **The database was not seeded with `sim:seed --profile=heavy`.** On
  2026-07-11 the command did not exist yet; on 2026-09-21 the table had been
  filled by earlier runs and demos. The figure to trust is the row count, not
  the profile name.
- **Run 1 does not report consumer batch write duration** — the consumer did
  not time its batches when it was made — **and predates `usage_events_recent_index`**
  (`3960979`). Run 2 includes both.
