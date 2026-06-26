# 0017. Admin authentication and authorization

- **Status:** Accepted
- **Date:** 2026-05-31

## Context

The API authenticates with project-scoped keys and needs no concept of a person.
The admin panel does: someone signs in, belongs to an organization, and may or
may not be allowed to void an invoice.

This introduces the first human identity in the system, and demo mode means
strangers will create those identities themselves on a shared instance.

## Decision

A `users` table with Laravel's standard authentication, plus membership:

```
users                 id, name, email, password, last_signed_in_at
organization_members  organization_id, user_id, role
```

Four roles, no per-resource permissions:

| Role | May do |
|---|---|
| `viewer` | Read everything within the organization |
| `admin` | Manage the catalog — meters, plans, versions, prices, customers, subscriptions — and operate webhooks: rotate a secret, replay a delivery |
| `billing_operator` | Everything `viewer` may do, plus the actions that move money: finalize an invoice, void it, record a payment, issue a credit note |
| `owner` | Everything, plus members, projects, API keys, and deleting the organization |

`admin` and `billing_operator` are deliberately **not** nested. Changing a price
and finalizing an invoice are different kinds of authority, and in a real billing
organization they usually belong to different people: one shapes the catalog, the
other signs off on what a customer is charged. Separating them is also what makes
the policy tests interesting — the two roles overlap on reads and are disjoint on
writes, so a policy that quietly grants everything to everyone fails a test
instead of passing unnoticed.

Authorization is enforced by policies at the handler boundary, not only by hiding
buttons in the UI. Tenant scope always comes from the session, never from the
request.

Demo sign-ups create a user who is `owner` of their own generated organization,
so the demo exercises the same path a real first user takes.

API keys and users stay separate concepts: a key authenticates a machine to a
project, a user authenticates a person to an organization. Neither is derived
from the other.

## Consequences

The panel has real access control that can be demonstrated, and the "hidden
button" anti-pattern is avoided — an `admin` calling an owner-only handler
directly is refused by the policy.

Four roles cover every screen this project has, and the split between catalog
authority and money authority is the part worth demonstrating: it is the
difference between a permission model and a list of checkboxes. Introducing
per-resource permissions later still means a migration and a policy rewrite,
which is recorded in the assumptions as a cost accepted knowingly.

Keeping users separate from API keys means two authentication paths to test, and
two places where authorization can be wrong. The alternative — deriving keys from
users — would tie a machine credential to a person's account lifecycle, which is
worse.

Demo mode makes self-registration a supported flow, so it needs rate limiting,
email validation at the format level, and no password reset email (there is no
mail infrastructure). A forgotten demo password means a new demo account, which
is acceptable for a demo and stated in the UI.

## Alternatives considered

**No users at all, HTTP basic auth on the panel.** Trivial and incompatible with
demo sign-up, per-organization scoping and any meaningful authorization story.

**A full RBAC package with per-resource permissions.** More capable than four
roles, and a configuration surface larger than the application it protects.

**Three roles, with money actions folded into `admin`.** One role fewer, and it
conflates catalog authority with the authority to charge someone — the
separation those two need is exactly what an admin panel for a billing system
should show.

**Reusing API keys to sign into the panel.** Removes a concept and conflates a
machine credential with a person, so revoking a key would sign someone out and a
shared key would be a shared account.

**Social sign-in.** Removes password handling and adds a provider dependency the
demo cannot rely on when it is running offline on a laptop.

## Accepted in M2

Users, memberships and the four roles are in place, and the split that this
decision argued for is now a test: the write permissions of `admin` and
`billing_operator` are disjoint, and every role walks the key-issuing handler to
show that only an owner gets through.

Authorization sits at the handler, as promised. The panel hides what a role may
not do, and the handler refuses it again — the test that matters calls the
handler directly as an `admin` and is refused, with the key still working
afterwards.

One thing changed shape in the building. Provisioning an organization cannot
check a membership, because it is the operation that creates the organization a
membership would refer to; its authority comes from outside, from an operator at
a console or from a sign-up form that demo mode opened. The owner membership is
written before the first key is issued, so the key is issued by somebody already
entitled to have one, and sign-up is not a path that skips the check.

Managing members is not built: roles are assigned by provisioning and by nothing
else yet. That is recorded in `docs/assumptions.md` rather than left to be
discovered.
