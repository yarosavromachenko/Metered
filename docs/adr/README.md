# Architecture decision records

One file per decision that was not obvious. Each records the situation, what was
chosen, what that costs, and what was rejected — the last part being the one that
makes an ADR worth writing. A decision with no rejected alternative was not a
decision.

An ADR is never edited to reflect a change of mind. It is superseded by a new one
that links back, so the reasoning at the time stays readable.

| # | Decision | Status |
|---|---|---|
| [0001](0001-modular-monolith.md) | Modular monolith with machine-enforced boundaries | **Accepted** |
| [0002](0002-partitioning-and-deduplication.md) | Partitioning and deduplication of usage events | Proposed |
| [0003](0003-redis-streams-ingestion.md) | Redis Streams for ingestion | Proposed |
| [0004](0004-aggregation-exactly-once-effect.md) | Aggregation with an exactly-once effect | Proposed |
| [0005](0005-transactional-outbox-inbox.md) | Transactional outbox and inbox | **Accepted** |
| [0006](0006-api-idempotency.md) | Idempotency keys for mutating endpoints | **Accepted** |
| [0007](0007-money-and-decimals.md) | Money and decimal arithmetic | **Accepted** |
| [0008](0008-double-entry-ledger.md) | Append-only double-entry ledger | Proposed |
| [0009](0009-clock-injection.md) | Time through an injected clock | **Accepted** |
| [0010](0010-period-close-and-invoice-numbering.md) | Period close, late events, invoice numbering | Proposed |
| [0011](0011-webhook-delivery.md) | Webhook signing, retries, breaker, SSRF guard | Proposed |
| [0012](0012-trace-context-propagation.md) | Trace context across async hops | Proposed |
| [0013](0013-multi-tenancy.md) | Multi-tenancy by row scoping | **Accepted** |
| [0014](0014-audit-log-hash-chain.md) | Hash-chained audit log | **Accepted** |
| [0015](0015-admin-ui-filament.md) | Admin panel on Filament | Proposed |
| [0016](0016-demo-mode-and-seed-profiles.md) | Demo mode and seed profiles | Proposed |
| [0017](0017-admin-authentication.md) | Admin authentication and authorization | Proposed |

Statuses: **Proposed** → **Accepted** → **Superseded by NNNN** / **Deprecated**.
