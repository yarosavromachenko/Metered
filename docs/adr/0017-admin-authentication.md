# 0017. Admin authentication and authorization

- **Status:** Proposed
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

Roles are `owner`, `admin` and `viewer` — three levels, no finer permissions.

- `viewer` reads everything within the organization.
- `admin` additionally performs operational actions: finalize, void, pay, replay
  a delivery, rotate a secret, manage the catalog.
- `owner` additionally manages members, projects and API keys, and deletes the
  organization.

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

Three roles cover every screen this project has. Introducing per-resource
permissions later means a migration and a policy rewrite, which is recorded in
the assumptions as a cost accepted knowingly.

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

**A full RBAC package with per-resource permissions.** More capable than three
roles, and a configuration surface larger than the application it protects.

**Reusing API keys to sign into the panel.** Removes a concept and conflates a
machine credential with a person, so revoking a key would sign someone out and a
shared key would be a shared account.

**Social sign-in.** Removes password handling and adds a provider dependency the
demo cannot rely on when it is running offline on a laptop.
