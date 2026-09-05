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
| `mixed` | Ingestion plus management API reads, the realistic pattern — [`k6/mixed.js`](../k6/mixed.js), `make load SCENARIO=mixed` against a seeded tenant; no run recorded yet |
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
| 2026-07-11 | `ingest-steady` | 40.7 | 31.6 ms | 36.1 ms | 570 ms | 0 | 0 |

`ingest-burst`, `mixed` and `close-periods` are not measured yet: the first two
need the management API reads, which arrived with M4 and are not scripted yet, and the last needs invoicing.

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

Parameters: `k6/ingest.js`, batches of 50 events, one project, one meter, one
customer. 30s warm-up at 2 VUs, then 5 minutes at a constant 40 requests a
second. `API_KEY_RATE_LIMIT_PER_MINUTE=1000000`, so the run measures ingestion
and not the per-key limiter. `usage_events` held 4.2 million rows at the start
of the third run. Three runs; the median run is reproduced below.

| Run | p50 | p90 | p95 | p99 | max | Requests | RPS | Errors | Shed (503) | Final lag |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 31.61 ms | 34.22 ms | 35.71 ms | 586.05 ms | 783.54 ms | 13,636 | 40.70 | 0 | 0 | 0 |
| 2 | 31.64 ms | 34.35 ms | 36.09 ms | 570.30 ms | 777.51 ms | 13,614 | 40.62 | 0 | 0 | 0 |
| 3 | 31.70 ms | 34.41 ms | 36.09 ms | 559.11 ms | 762.11 ms | 13,654 | 40.76 | 0 | 0 | 0 |

Run-to-run variance is under 1% at p50, p90 and p95, and under 5% at p99.

Raw output of run 2, the median:

```
     ✓ answered as the API promises
     ✓ never a server error
     batch_size.....................: min=50      med=50       p(90)=50       p(95)=50       p(99)=50       max=50
   ✓ checks.........................: 100.00% 27228 out of 27228
     data_received..................: 5.1 MB  15 kB/s
     data_sent......................: 138 MB  411 kB/s
     events_accepted................: 680700  2031.039429/s
     http_req_blocked...............: min=1.46µs  med=5.7µs    p(90)=6.71µs   p(95)=7.5µs    p(99)=14.32µs  max=577.01µs
     http_req_connecting............: min=0s      med=0s       p(90)=0s       p(95)=0s       p(99)=0s       max=334µs
     http_req_duration..............: min=28.6ms  med=31.56ms  p(90)=34.29ms  p(95)=35.98ms  p(99)=565.26ms max=777.51ms
       { expected_response:true }...: min=28.6ms  med=31.56ms  p(90)=34.29ms  p(95)=35.98ms  p(99)=565.26ms max=777.51ms
   ✗ { phase:plateau }..............: min=29.19ms med=31.64ms  p(90)=34.35ms  p(95)=36.09ms  p(99)=570.3ms  max=777.51ms
     http_req_failed................: 0.00%   0 out of 13614
     http_req_waiting...............: min=28.51ms med=31.4ms   p(90)=34.12ms  p(95)=35.8ms   p(99)=565.12ms max=777.34ms
     http_reqs......................: 13614   40.620789/s
     iterations.....................: 13614   40.620789/s
     vus............................: 4       min=0              max=7

ERRO thresholds on metrics 'http_req_duration{phase:plateau}' have been crossed
```

**Reading.** Throughput is real: 2,031 events a second accepted for five
minutes, no errors, nothing shed, and a consumer group that ended every run at
zero lag — the pipeline drained as fast as it filled, which is the only
condition under which a throughput number means anything.

The `p(95)<50` threshold holds with room to spare. **`p(99)<150` does not, and
the run therefore fails.** That threshold was written before the endpoint
existed and it has not been touched: a target chosen after seeing the result is
not a target. What follows is what the tail actually is.

## What the p99 is, and what it is not

About 2% of requests take 500–780 ms while the median holds at 31 ms, in bursts
roughly every 5.3 seconds. The shape is the same from two independent clients
(k6 inside the Docker network, a Python client on the host) and Octane's own
per-request timing agrees, so it is the server and not the measurement.

What it is **not** — each ruled out by re-running the profile with the variable
changed, and none of them moved the tail:

- Not the consumer, the outbox relay or Horizon: the tail is unchanged with all
  three stopped.
- Not Redis. `redis-cli --latency` during a run peaks at 23 ms, and Redis is
  single-threaded, so a server-side stall would have shown there. Disabling RDB
  snapshots changes nothing either, although `save 60 10000` does fire every few
  seconds at this write rate.
- Not Octane worker recycling: `--max-requests=100000` changes nothing.
- Not PHP garbage collection: `zend.enable_gc=0` changes nothing.
- Not debug mode: `APP_DEBUG=false` with `LOG_LEVEL=error` changes nothing.
- Not the machine: load average stays near 2.1 of 16 threads, and the app
  container uses 1.3–1.9 cores.

Where it **is**: above the ingestion handler. With the handler timed from the
inside over 3,243 requests, its slowest pass was 21.7 ms and its typical pass
under 3 ms — the backlog check and the pipelined `XADD` are not where the time
goes. A route that does nothing, driven at the same 40 requests a second, has a
p99 of 5.2 ms and a maximum of 8.9 ms, so the framework is not stalling on its
own either. The cost sits between the two, in the middleware and the validation
of a fifty-event body, and so does the tail.

That is as far as this can be taken by changing one variable at a time. Naming
the exact cause needs a trace across the request, which is what M8 builds; the
number stays recorded and failing until then rather than being explained away.

Two consequences worth stating plainly. The median is dominated by validating
fifty nested events, not by the stream write, which is the opposite of where the
design put its attention — ADR-0003 argues the endpoint is cheap because it only
appends to Redis, and appending to Redis is indeed about a millisecond of it.
And a client sending batches of one sees a 7.6 ms median, so per-event cost is
roughly 0.6 ms of framework time, which is the number worth attacking.

## Notes

Things to check before trusting any number here:

- Was the consumer keeping up, or did the lag simply grow for five minutes?
  Throughput without a bounded lag is not throughput.
- Did any request get a `503` from backpressure? Those are not errors, but they
  must be counted separately.
- Was `pcov` or `xdebug` loaded? Either invalidates the run.

Deviations from the method in the run above, stated rather than buried:

- **`pcov` was loaded.** It ships in the development image and the stack under
  test is the one `make up` builds, so the run was made with the extension
  present but not collecting. A run with it removed is owed here before the
  final numbers in M9.
- **The database was not seeded with `sim:seed --profile=heavy`**, because that
  command arrives in M7. The table was filled by the ingestion path itself
  instead, and had grown to 4.2 million rows by the third run, which is the same
  order as the `heavy` profile is specified to produce. The figure to trust is
  the row count, not the profile name.
- **Consumer batch write duration is not reported**, because the consumer does
  not yet time its own batches. It reports what it read, counted and rejected,
  which was enough to confirm zero lag but not enough to put a number here.
- **The run predates `usage_events_recent_index`** (`3960979`), which the
  usage explorer needed ([`query-plans.md`](query-plans.md), query 7). The
  index is one more descent per inserted row. It sits on the consumer's write,
  not on the request path this run measured, but the next run has to include
  it before these numbers are quoted as current.
