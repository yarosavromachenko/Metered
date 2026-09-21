# 0019. Export metrics through OpenTelemetry, and read gauges in one process

- **Status:** Accepted
- **Date:** 2026-09-16
- **Supersedes:** —
- **Superseded by:** —

## Context

The metrics in `docs/observability.md` fall into two kinds. Counters and
histograms — requests answered, events rejected, batch write time, webhook
attempts, invoices finalized — are recorded where the work happens, in Octane
workers, Horizon workers and the two daemons. Gauges — stream depth, outbox
lag, queue depth, open breakers — describe shared state that any process could
read, and that reads the same whoever reads it.

PHP runs many short-lived or single-threaded processes. The usual Prometheus
client libraries for PHP expose an endpoint per process, or keep their state in
Redis or APCu so that one endpoint can report for all workers. Traces already
leave every process as OTLP to a collector (ADR-0012), and
`open-telemetry/sdk` is already locked.

The SDK has no background thread in PHP. Nothing is exported on a timer: a
meter's reader is collected when something asks for it.

## Decision

- Counters and histograms are recorded through a port,
  `Shared\Application\Metrics\Metrics`, implemented over an OpenTelemetry
  meter and exported as OTLP/HTTP to the collector — the same endpoint as the
  traces. The collector serves them in the Prometheus format on `:8889`;
  Prometheus scrapes the collector and nothing else.
- Temporality is cumulative. Each process reports its own totals under its own
  `service.instance.id`, which becomes the `instance` label; dashboards and
  alerts aggregate over it. The meter provider is built at boot, so an Octane
  worker keeps one for its life rather than one per request.
- Prometheus runs with `created-timestamp-zero-ingestion`. The collector's
  protobuf exposition carries each series' start time, and Prometheus records
  a zero there, so `rate()` and `increase()` count a new series' first
  increments. Without it, a counter that a process increments once never
  shows in a rate at all.
- Application code records integers only — durations in nanoseconds — and the
  histogram's `Scale` decides how it is exported and bucketed, so the
  no-float rule for the application layers holds.
- Readers are collected at the idle points of each long-running process
  (`TelemetryFlush`: an answered Octane request, a queue worker's pass, a
  daemon's loop), at most every ten seconds, and at shutdown.
- Gauges are observable instruments read by one process, `metrics:observe`,
  every `METRICS_OBSERVE_INTERVAL` seconds (15). Modules contribute
  `GaugeSource`s under a tag; the observer reports exactly the last pass's
  readings, so a value that went away stops being reported.
- With `OTEL_SDK_DISABLED=true` the meter is a no-op, as the tracer is.

## Consequences

- No new dependency, one telemetry path, one configuration for traces and
  metrics, and no per-process endpoint to discover.
- Counters are exported up to ten seconds late, and a worker that stops
  receiving work stops exporting until its next idle point; Prometheus sees
  the last value it received.
- Series churn: a worker that restarts (Octane's request limit, a Horizon
  restart) starts a new `instance` series, and the collector drops a silent
  instance after five minutes (`metric_expiration`). Octane restarts a worker
  after 10,000 requests rather than its default 500: FrankenPHP sends most of
  the traffic to the first idle workers, and at 500 a busy one was replaced
  every half minute.
- The collector is on the metrics path: if it is down, metrics are lost for
  that time rather than buffered. It is one container with no state.
- The gauges cost one set of queries per interval, not one per worker.

## Alternatives considered

- **A Prometheus client library with shared storage** (`promphp/prometheus_client_php`
  on Redis or APCu). Workable and familiar, but a second telemetry path beside
  OTLP, a new dependency, and Redis writes on the hot path of ingestion for
  every counter increment.
- **Delta temporality and a `deltatocumulative` processor in the collector.**
  Avoids a series per process, but merges deltas from many processes into one
  stream, where out-of-order arrivals are dropped. Cumulative per instance is
  the simpler thing to reason about.
- **Gauges read by every worker, or at scrape time by the web app.** Every
  worker multiplies the same queries and series; a `/metrics` route on the
  app ties a Prometheus scrape to a request worker and to the database it
  queries.
