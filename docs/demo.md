# Running the demo

The point of this document: someone who has never seen the repository should get
from `git clone` to a populated admin panel without reading anything else.

```bash
git clone https://github.com/yarosavromachenko/Metered.git metered
cd metered
make demo
```

There is no hosted instance. Everything — API, workers, database, Redis,
tracing, dashboards — runs from this repository via Docker Compose, so the demo
cannot rot, cost money, or be abused.

## What `make demo` does

1. Creates `.env` from `.env.example` if there is none and switches `APP_DEMO`
   on, restarting a stack that was already running so it sees the change.
2. Installs dependencies into the working tree if `vendor/` is missing — the
   containers mount the tree over the image's own copy.
3. Builds and starts the stack and runs the migrations.
4. Runs `make demo-reset`: deletes every demo organization, seeds the showcase,
   adds its read-only login, and starts a traffic generator.
5. Prints the URLs.

On a laptop the seed takes about two minutes; the first run adds the image
build and `composer install`.

| | |
|---|---|
| Admin panel | <http://localhost:8080/admin> |
| Grafana | <http://localhost:3000> — dashboards for ingestion, processing, delivery and billing, the live load, and traces under Explore → Tempo |
| Prometheus | <http://localhost:9090> |
| Alertmanager | <http://localhost:9093> |
| Webhook receiver | <http://localhost:8089> |
| Horizon | <http://localhost:8080/horizon> |
| Mailpit | <http://localhost:8025> |
| API | <http://localhost:8080/api/v1> |

If another project on your machine already holds one of those ports, set
`APP_PORT`, `GRAFANA_PORT`, `WEBHOOK_RECEIVER_PORT`, `MAILPIT_WEB_PORT`,
`PROMETHEUS_PORT`, `ALERTMANAGER_PORT` and friends in `.env`; nothing inside the network cares which host port it is
reached on.

## Two ways in

**Look around the showcase.** Sign in as `demo@metered.test` /
`metered-demo` — the sign-in page shows both. "Northwind Cloud" has three months
of history: 120 customers on four plans covering every pricing model, about two
million usage events, some 230 invoices — most paid, a few overdue, one voided
with a credit note — and webhook deliveries in every state. The account is a
viewer: everyone sees the same data, and nobody can change it.

**Sign up.** You get an organization, a project and an owner account of your
own, filled in the background with the `small` profile within seconds. Break
it — void invoices, point webhooks at the receiver's failing modes, send odd
usage — and **Reset demo data** on the projects page empties it and seeds it
again, keeping your members, projects and keys. A demo tenant nobody has
signed in to for seven days is deleted by a daily sweep.

## Seed profiles

| Profile | Contents | Used by |
|---|---|---|
| `small` | 12 customers, ~30k events over 60 days, a week of it through the API | Every sign-up; development |
| `demo` | 120 customers, ~2M events over 90 days, ~230 invoices | The showcase |
| `heavy` | 1,000 customers, ~20M events over 90 days | Benchmarking and index work only |

Every profile carries the same awkward cases, because a demo of only happy
paths demonstrates nothing: subscriptions anchored on the 29th, 30th and 31st,
so the month-end clamp shows in real invoices; a customer who never uses
anything; one who changes plan and one who cancels; duplicates, late events and
events naming a meter or customer that does not exist; and one webhook endpoint
for each mode of the demo receiver — accepting, flaky, slow, down (its circuit
breaker opens) and gone.

## How a tenant is seeded

`sim:seed` works as a client and a history would:

1. The catalog, webhook endpoints, customers and backdated subscriptions are
   created **through the public API**.
2. History older than the last few days is loaded with `COPY` — events and the
   hourly aggregates the consumer would have written — and `usage:reconcile`
   must find no drift, or the seed stops. Two million events over HTTP would
   take far longer than anyone waits, and the acceptance window refuses events
   older than a week anyway.
3. Every period that has ended is closed by the ordinary period close, so the
   invoices are built by the same code as any other.
4. The invoices are settled through the API: paid some days after issue, a few
   left overdue, one voided.
5. The last days of usage go **through the API**, some of it late for periods
   already closed, so the next invoice carries late lines.

The reasoning, and what changed from the first proposal, is in
[ADR-0016](adr/0016-demo-mode-and-seed-profiles.md).

## Other commands

```bash
make demo-reset   # delete every demo tenant, seed the showcase again
make load         # k6 load profile against the local stack
make down         # stop, keep data
make destroy      # stop and delete volumes
```

```bash
docker compose exec app php artisan sim:seed --profile=small --organization="Acme"
docker compose exec app php artisan sim:traffic --key=<key> --rps=200 --duration=120 --dup-rate=0.02
docker compose exec app php artisan sim:time-travel --by=P1M
docker compose exec app php artisan sim:chaos kill-consumer
```

`sim:chaos` breaks one part of the running stack — the consumer, the usage Redis, the
outbox relay, a webhook receiver — while traffic flows, and then checks the
invariants: every accepted event stored exactly once, the aggregates agreeing
with the events under them, every event published and every delivery
accounted for. Those runs are the evidence behind the reliability claims in
the README.

## What is running

| Container | Role |
|---|---|
| `app` | Octane on FrankenPHP: the API, the admin panel and Horizon's dashboard |
| `outbox-relay` | Publishes committed integration events to the queue |
| `horizon` | Queue workers: `billing`, `webhooks`, `default`, each supervised separately |
| `usage-consumer` | Redis Stream → PostgreSQL, with aggregates in the same transaction |
| `scheduler` | Period close, webhook dispatch, partition creation, the idle-demo sweep, and maintenance: expired idempotency keys, old outbox rows, audit chain verification |
| `postgres` | PostgreSQL 18 |
| `pgbouncer` | Transaction pooling for the web tier only; the daemons connect directly |
| `redis` | Cache, sessions and queues |
| `redis-usage` | The ingestion stream and the deduplication keys, with a memory limit of its own (ADR-0002) |
| `mailpit` | Catches every outgoing message and shows it in a browser |
| `webhook-receiver` | A stand-in for a tenant's system: receives, verifies and lists webhooks, with purpose-broken modes |
| `grafana` | Five provisioned dashboards — four on Prometheus, the live-load one on PostgreSQL — and Tempo's traces under Explore |
| `otel-collector`, `tempo` | Receive OTLP from every process; Tempo keeps the traces |
| `prometheus` | Scrapes the collector and evaluates the alert rules |
| `alertmanager` | Groups firing alerts and sends them as email to Mailpit |
| `metrics-observer` | `metrics:observe`: reads the gauges — stream, usage Redis memory, outbox, queues, breakers, dead letters — every 15 seconds |
| `k6` | Load scenarios, under the `load` profile |

## Requirements

Docker and Docker Compose. Nothing else — no local PHP, PostgreSQL or Redis.
The `demo` profile needs roughly 4 GB of RAM; the full profile with the
observability stack is more comfortable with 6 GB.

The containers that mount the source tree run as your own user, so a file one of
them creates — a published config, a generated migration — belongs to you rather
than to root. If your ids are not 1000, set `DOCKER_UID` and `DOCKER_GID`.
