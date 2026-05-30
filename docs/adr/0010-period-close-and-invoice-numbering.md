# 0010. Period close, late events and invoice numbering

- **Status:** Proposed
- **Date:** 2026-05-30

## Context

Three problems arrive together at the end of a billing period.

**When to close.** Closing the instant a period ends means events still in flight
land after the invoice is finalized — and a finalized invoice cannot be edited.

**Closing twice.** A scheduler that overlaps, a retried job, or an operator
clicking twice must not produce two invoices for one period.

**Numbering.** Invoice numbers are frequently required to be sequential with no
gaps. A PostgreSQL `SEQUENCE` does not qualify: it is non-transactional by
design, so a rolled-back transaction burns a number permanently.

## Decision

**Grace window.** A period is closed at `period_end + grace`, default one hour.
Events arriving inside the window still make it onto the invoice.

**Late events.** An event whose `occurred_at` falls in an already finalized
period is billed on the *next* invoice, as its own line flagged `late`, showing
which period it belongs to. It is never merged silently into current usage.

**Duplicate protection lives in the database.** `UNIQUE (subscription_id,
period_start, period_end)` on invoices. A second close attempt fails on the
constraint, and the job treats that as success — the invoice exists, which is the
desired state. `WithoutOverlapping` on the job is an optimisation that avoids
wasted work, never the guarantee.

**Gapless numbering.** A counter row per organization in
`invoice_number_sequences`, incremented under `SELECT ... FOR UPDATE` inside the
finalizing transaction. A rollback rolls the number back with it.

`billing:close-periods` queues one job per due subscription on the `billing`
queue.

## Consequences

Invoices are correct with respect to late data, and the customer can see exactly
which period a late line belongs to — the alternative, a silent adjustment, is
what makes billing disputes unresolvable.

Exactly one invoice per subscription-period, guaranteed by a constraint rather
than by a lock. This is the general rule of this codebase: locks make things
faster, constraints make them correct.

Numbering is gapless and contention on the counter is per organization, so
tenants do not serialise against each other.

The costs are real. The grace window delays invoices by an hour. The row lock
serialises finalization within one organization — fifty parallel finalizations
become a queue, which is why there is a concurrency test proving the result is
still correct rather than merely fast. And a late event produces a visible extra
line customers will ask about, so the line explains itself.

## Alternatives considered

**Close immediately at period end.** Simpler, and it either loses late events or
forces invoice mutation. Mutable finalized invoices break the ledger.

**Adjust the already-finalized invoice.** Rejected: it contradicts the
append-only ledger and the immutability that makes a finalized document
meaningful.

**PostgreSQL `SEQUENCE` for numbering.** Fast and gap-prone by design. Fails the
one requirement.

**Assign numbers in a background pass after finalization.** Would remove the lock
from the hot path, at the price of a window in which an invoice exists without a
number. Rejected as more confusing than the contention it avoids.

**Advisory locks instead of a row lock.** They do not survive PgBouncer's
transaction pooling on the web tier, and the row lock is already transactional.
