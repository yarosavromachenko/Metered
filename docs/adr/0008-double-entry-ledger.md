# 0008. Append-only double-entry ledger

- **Status:** Accepted
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

## Accepted in M5

Built as decided: three postings — a finalized invoice books `Dr Accounts
Receivable / Cr Revenue`, a payment `Dr Cash / Cr Accounts Receivable`, a credit
note `Dr Revenue / Cr Accounts Receivable` — each built by a named constructor on
`LedgerTransaction` that refuses to exist unless its debits equal its credits in
one currency. A balance is summed from entries on the account's natural side;
nothing stores one.

What the building changed:

**Triggers, not `REVOKE`.** The application's role owns its tables, and in
development and CI it is the superuser; neither an owner nor a superuser is bound
by revoked privileges. `UPDATE`, `DELETE` and `TRUNCATE` on the ledger tables are
refused by triggers instead, which bind everyone, and a test asserts each one.

**The balance check runs at commit.** Entries are inserted one row at a time, so
a row-level check would see a half-written transaction. A deferred constraint
trigger checks, when the database transaction commits, that each ledger
transaction's entries balance in a single currency. Tests force it with
`SET CONSTRAINTS ALL IMMEDIATE`, because the suite's wrapping transaction never
commits.

**Each movement of an invoice is booked once.** `UNIQUE (invoice_id, posting)`:
an invoice is finalized once, paid once, credited once, and a retried job that
tries to book a second time fails on the constraint.

**No account rows, and no `FOR UPDATE` on them — for now.** An account is the
pair of a customer and one of three names; there is no table of accounts to lock.
The lock this decision describes exists to serialise applying a prepaid balance,
and prepaid credit — the `customer_credit` account — is not in v1
([`assumptions.md`](../assumptions.md), 27). What serialises the movements that
do exist is the invoice row: finalize, pay and void each take it with
`SELECT ... FOR UPDATE`, and the status it holds decides whether the movement is
allowed.

**Deleting a tenant with money history needs a path of its own.** Every
invoicing table restricts deletion of its project. Demo tenants are deleted
after a week (ADR-0016), so M7 needs a purge that is explicit about what it
removes rather than a cascade that the triggers would refuse.
