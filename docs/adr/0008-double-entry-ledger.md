# 0008. Append-only double-entry ledger

- **Status:** Proposed
- **Date:** 2026-05-30

## Context

"How much does this customer owe?" has to have one answer, and it has to survive
a void, a credit note, a partial payment and a prepaid balance applied halfway
through. A mutable `balance` column answers the question quickly and cannot
explain itself: when it disagrees with the invoices, there is no way to tell
which is wrong or when they diverged.

Accounting solved this several centuries ago. Recording both sides of every
movement makes the books self-checking, and makes any balance a derived figure
with a full explanation behind it.

## Decision

An append-only double-entry ledger.

Accounts per customer and project: `accounts_receivable`, `revenue`, `cash`,
`customer_credit`.

| Event | Entries |
|---|---|
| Invoice finalized | Dr Accounts Receivable / Cr Revenue |
| Payment received | Dr Cash / Cr Accounts Receivable |
| Credit note issued | Dr Revenue / Cr Accounts Receivable |
| Prepaid credit applied | Dr Customer Credit / Cr Accounts Receivable |

Invariants:

- Debits equal credits within a ledger transaction, checked in the domain and
  again by a database constraint.
- Entries are never updated or deleted. `UPDATE` and `DELETE` are revoked on the
  tables at the database level, and a test asserts that an attempt fails.
- A balance is always an aggregate over entries. There is no stored balance.
- Reading a balance in order to change it happens under
  `SELECT ... FOR UPDATE` on the account row.

## Consequences

Every figure can be explained by the entries beneath it, which is what makes a
billing system auditable rather than merely plausible.

Corrections are reversals, not edits. Voiding a finalized invoice issues a credit
note; the original stays visible. That is what an accountant expects, and it is
what makes a finalized document trustworthy.

Revoking `UPDATE` and `DELETE` at the database level means even a bug or a
careless migration cannot quietly rewrite history. It also means a genuine
mistake requires a compensating entry, which is more work and the correct amount
of work.

The cost is write volume — four rows where a naive design writes one — and read
cost, since a balance is an aggregate. With an index on
`(account_id, created_at)` this is fine at the scale in question; a periodic
balance snapshot is the known escape hatch if it stops being fine, and it would
be a cache over the entries, never a replacement.

The `FOR UPDATE` lock on credit application serialises concurrent operations on
one account. That is intentional: two parallel applications of a prepaid balance
must not both succeed against the same funds.

## Alternatives considered

**A `balance` column on the customer.** Fast, simple, and unable to answer "why".
It also invites lost updates under concurrency.

**Single-entry transaction log.** Records movement without the self-check. The
"debits equal credits" invariant is precisely the property that catches a class
of bugs before a customer does.

**A dedicated ledger service or third-party product.** Overkill here, and it
would move the most interesting code out of the repository.

**Allowing entry corrections by an admin.** Rejected. The moment history is
editable, no figure derived from it can be trusted.
