# 0020. Audit chains per organization

- **Status:** Accepted
- **Date:** 2026-10-01
- **Amends:** [ADR-0014](0014-audit-log-hash-chain.md)

## Context

ADR-0014 chains every audit entry to the one before it, and appending takes a
transaction-scoped advisory lock with one key for the whole table. Every
audited write in the system therefore queues behind every other, whichever
tenant it belongs to. Holding that lock is cheap, but it is held to the end of
the surrounding transaction — a period close, a demo reset — so one tenant's
long transaction stalls another tenant's price change. Measured: with one
organization's transaction holding the lock, another organization's audited
write waited 9.1 seconds for it.

The chain's guarantee never needed one global order. "Nobody edited this
tenant's history" is a statement about one tenant's entries.

## Decision

One chain per organization.

- `audit_log.organization_id` (nullable `uuid`, no foreign key). Every entry
  names its organization; `AuditEntry` takes it as a required argument with no
  default, so a forgotten one cannot quietly land somewhere else.
- **Entries written before 1.1.0 keep no organization.** They are the platform
  chain, valid exactly as written; nothing is rewritten, and nothing could be,
  with `UPDATE` revoked. New entries all belong to an organization, so the
  platform chain stays as it is unless an act ever belongs to none.
- **The hash covers the organization only when there is one.** Platform
  entries hash as they always did, so the known-answer vector and every stored
  hash stay valid, and an organization's entry cannot be moved into another
  chain and still verify.
- **The lock is per chain:** `pg_advisory_xact_lock(class, hashtext(org))`, in
  the two-key form so its namespace cannot meet another lock's. Two writers to
  the same chain still serialise; writers to different chains do not wait.
- **A chain cannot fork, in the database:**
  `UNIQUE NULLS NOT DISTINCT (organization_id, prev_hash)`. The lock is what
  makes the links come out in order; the constraint is what holds if it ever
  does not.
- **`audit:verify` checks every chain in one pass** over `sequence`, keeping
  each chain's last hash, and names the chain that broke.

No foreign key to `organizations`: a purged demo organization's chain outlives
it — that is what an audit trail is for (assumption 40). The purge is recorded
in the organization's own chain, as its last entry.

## Consequences

- Audited writes of different tenants run in parallel; the contention ADR-0014
  named is now per tenant, where it reflects real ordering.
- **The tail is weaker.** The newest entry of a chain has nothing linking to it
  yet, so deleting it is not detectable by the chain alone. With one chain that
  was a single entry, covered as soon as anyone wrote the next; now it is the
  newest entry of every organization — and for a tenant that has gone quiet, or
  a purged one, it stays uncovered. Deleting an organization's entire chain is
  undetectable for the same reason. The external anchor ADR-0014 notes as an
  extension — publishing each chain's latest hash outside the system — is what
  would close both.
- Chains commit independently, so `audit:verify` reads the table in one
  `REPEATABLE READ` snapshot: paged reads under `READ COMMITTED` could miss an
  entry that committed late, or read one twice, and report a break that is not
  there. Memory grows with the number of organizations, not of entries.

## Alternatives considered

**Per project rather than per organization.** Finer still, but members,
projects and keys are organization-level acts, and an organization is the unit
an auditor asks about.

**Keep one chain and shorten the lock.** Recording outside the business
transaction would release the lock early, and would also let an audited change
commit without its entry — the property the log exists for.

**Rewrite the old entries into per-organization chains.** Impossible without
`UPDATE`, and a migration that recomputes an audit trail is exactly what the
trail should make suspicious.
