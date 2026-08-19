# 0010. Period close, late events and invoice numbering

- **Status:** Accepted
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

## Accepted in M5

Built as decided. `billing:close-periods` runs every five minutes from the
scheduler and queues one `CloseSubscriptionPeriodsJob` per subscription with a
period past its grace window, on the `billing` queue. The job builds a draft
from the aggregates and finalizes it; `UNIQUE (subscription_id, period_start,
period_end)` keeps it to one invoice per period, and `WithoutOverlapping` on the
subscription only saves the wasted build. Two concurrency tests hold the result
in real processes: eight workers closing one period build one invoice, and fifty
finalizations at once — ten of which roll back after taking a number — leave
numbers 1 to 40 with no gap and no duplicate.

What the building changed:

**A losing close is a quiet no-op, not a caught violation.** The draft is
inserted with `ON CONFLICT DO NOTHING`. Catching a unique violation inside the
transaction would abort everything after it; the second close is the expected
case, and it simply finds the invoice there.

**One counter table for every kind of document.** `document_sequences` holds a
row per organization and kind — invoices and credit notes — advanced by one
upsert that creates the row at one or increments it and holds its lock until
the transaction ends. The counter refuses to run outside a transaction, where a
number could be used without being rolled back with its invoice.

**How far back a late line can reach.** A late line is found by pricing an
earlier period again at what it now holds and billing the difference from what
was billed — under tiers, the late units cannot be priced on their own. The
periods looked at are those that ended within the ingestion acceptance window
plus the grace before the new period starts: an event older than that is
rejected at the door, so nothing later can reach them. A volume discount can
make more usage cost less; the late line then bills zero rather than paying
money back ([`assumptions.md`](../assumptions.md), 25).

**Hourly buckets decide which period usage belongs to.** Invoices read the
hourly aggregates, and a bucket belongs to the period its start falls in —
exactly what `bucket_start >= start AND bucket_start < end` says, whatever
instant the subscription was anchored at (assumptions, 24).

**An invoice for nothing is settled at once.** A zero total is finalized and
paid in one step and books no ledger entries: there is nothing to collect, and a
transaction of zero entries is not a transaction.

**Nothing was running the scheduler.** `routes/console.php` had its first entry
in M2 and no process to run it; the stack now has a `scheduler` service. The
first run through Horizon also found that a job which throws and will be retried
left its trace scope open, and the retry then failed on that instead of on the
real error. Fixed in `QueueTracing`.
