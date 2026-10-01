# 0014. Hash-chained audit log

- **Status:** Accepted
- **Date:** 2026-05-30

## Context

In a billing system the questions that follow an incident are about people, not
code: who voided that invoice, who rotated that secret, who changed that price
and when. An ordinary log answers this only if nobody with database access has a
reason to edit it — and the person most likely to have that reason is also the
one with access.

## Decision

An append-only `audit_log` table: `actor`, `action`, `subject_type`,
`subject_id`, `payload`, `occurred_at`, `prev_hash`, `hash`.

Each row's `hash` covers its own contents together with the previous row's hash,
forming a chain. `UPDATE` and `DELETE` are revoked on the table. The command
`audit:verify` walks the chain and reports the first broken link.

Recorded: every mutating admin action, API key creation and revocation, webhook
secret rotation, invoice finalization, void, payment, and plan version creation.

Not recorded: reads, and the contents of secrets.

## Consequences

Tampering becomes detectable rather than merely discouraged. Changing a row
breaks its hash; changing the row and its hash breaks the next one; rewriting the
whole chain is possible but no longer a quiet edit.

Deleting a row is equally visible, because the chain no longer links.

`audit:verify` runs on a schedule, so a broken chain is found on its own rather
than during the incident it would have explained.

Costs: a write on every mutating action, and a chain that must be built in a
deterministic order — concurrent writers need serialisation at the point the
chain link is computed, which is a contention point to be aware of. Verification
is a full scan, so it is scheduled rather than run per request.

This detects tampering; it does not prevent it. Someone with full database
access and enough determination can recompute the chain. Preventing that needs an
external anchor — publishing periodic hashes somewhere outside the system — which
is noted as a possible extension rather than implemented.

## Alternatives considered

**A plain audit table.** Covers the common case and is trivially editable, which
defeats the purpose in exactly the scenario that matters.

**Laravel model events into a log table.** Convenient and framework-coupled, and
it records model changes rather than intent: "invoice.status changed to void" is
much less useful than "user X voided invoice Y because Z".

**Append-only storage outside the database.** Stronger, and it splits the audit
trail from the data it describes, losing transactional consistency between them.

**Signing each row with a private key.** Stronger than a chain, and it introduces
key management, which needs its own audit trail — recursion the project does not
need.


## Accepted in M1

The chain is built and verified, and three kinds of damage are distinguished,
each with a test: contents altered, a row removed, and a hash rewritten to match
altered contents.

The revocation is weaker than it first appears, and the tests say so: a role
with enough privilege — a superuser, or anyone who can `GRANT` to themselves —
bypasses it. Permissions discourage tampering; the chain is what detects it.
Running the application as a non-superuser role is therefore a deployment
requirement rather than an optional hardening step.

The encoding is pinned by a known-answer vector. Mutation testing showed that
changing a JSON flag broke no test, which would have meant a future encoding
change making every stored entry look tampered with.

The scheduler runs `audit:verify` daily at 03:50; a broken chain exits
non-zero, which the scheduler records as a failed run.

## Amended in 1.1.0

One chain per organization instead of one for the whole table, with the lock
taken per chain: [ADR-0020](0020-audit-chains-per-organization.md). Entries
written before it form the platform chain and verify as they did.
