# 0013. Multi-tenancy by row scoping

- **Status:** Accepted
- **Date:** 2026-05-30

## Context

Every tenant-owned row belongs to an organization and a project. A query that
forgets the filter does not fail — it returns another tenant's data, and in the
admin panel it renders that data on screen. This is the failure mode with the
worst consequences and the least visible symptoms, because everything looks like
it is working.

The demo makes it sharper: strangers create accounts on the same instance, so
isolation is exercised by people with no reason to be careful.

## Decision

Row-level scoping, made structurally hard to forget:

- Every tenant-owned table carries `organization_id` and `project_id`.
- Repository methods take the tenant context as an explicit argument. There is no
  ambient "current tenant" that can be absent or stale.
- The API key determines the project; no route contains a tenant identifier, so a
  client cannot address another tenant even by mistake.
- In the admin panel, scope comes from the authenticated session, and every
  resource query is scoped by both columns.
- Every admin screen ships with a test that signs in as tenant A and asserts that
  tenant B's rows are neither listed nor reachable by direct id.

PostgreSQL row-level security is a stretch goal as defence in depth, not the
primary mechanism.

## Consequences

Isolation is enforced where the query is written, which is where it is forgotten,
and the explicit argument means "which tenant?" cannot be answered by ambient
state that a background job forgot to set.

Composite indexes lead with `project_id`, so scoping is also what makes the
queries fast — the correct thing and the fast thing are the same thing.

The cost is repetition: the tenant context is threaded through nearly every
signature in the system. That is accepted deliberately, because the alternative —
a global scope applied automatically — hides the filter and fails silently when
it is missing.

Without row-level security, a raw SQL query written outside a repository could
still bypass the scope. Arch tests restrict where raw queries may live, and RLS
remains the answer if this ever needs to be airtight rather than merely
disciplined.

## Alternatives considered

**A database or schema per tenant.** The strongest isolation available. Rejected:
migrations across thousands of schemas, connection management, and a demo that
creates a schema per visitor.

**Laravel global scopes.** Automatic and therefore invisible. They apply to
Eloquent only, miss query-builder and raw SQL paths, and silently do nothing when
the ambient tenant is not set — which is exactly the situation in a queued job.

**Row-level security as the primary mechanism.** The most robust option, and it
depends on a session variable that PgBouncer's transaction pooling makes
unreliable on the web tier. Kept as defence in depth where it can be applied.

**A `tenant_id` on a single top-level table, joining for the rest.** Fewer
columns, more joins, and a scoping mistake in one join leaks everything beneath
it.


## Accepted in M0

Accepted as the model to build against; the tables that carry
`organization_id` and `project_id` arrive with the modules that own them. What
M0 fixes is the shape: repositories live behind module contracts, so the tenant
argument has a single place to be required rather than being reconstructed from
ambient state at each call site.

## Revisited in M2

Row scoping is now real: `organizations`, `projects` and `api_keys` carry the
columns, repositories take a `TenantContext` rather than two loose ids, and the
schema enforces the part a handler cannot — `api_keys` references
`(project_id, organization_id, environment)` as a composite foreign key, so a
test key cannot belong to a live project and no row can claim an organization
its project does not belong to.

The panel's scope is the session, as this decision asked. Filament's own
multi-tenancy was considered and not used: it models one tenant, and this system
scopes by organization *and* project, so the two halves would have been split
between a URL segment and the session.

Row-level security did not land. It was a stretch goal here, isolation does not
depend on it, and it is listed under what is deliberately not here in the README
rather than quietly dropped.
