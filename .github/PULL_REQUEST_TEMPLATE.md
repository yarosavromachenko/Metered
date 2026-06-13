## What and why

<!-- One paragraph. What changes, and what problem it solves. Link the milestone from docs/roadmap.md. -->

Milestone: <!-- M0 / M1 / ... -->

## Decisions

<!-- Any non-obvious choice made here. If it is architectural, link the ADR. -->

- ADR: <!-- docs/adr/00NN-... or "none needed, because ..." -->
- New dependencies: <!-- name + why writing it by hand is worse, or "none" -->

## How it was verified

<!-- Which test levels cover this, and anything you checked by hand. -->

## Definition of done

- [ ] Tests written first for domain logic; every relevant level covered
- [ ] `make check` green (Pint, Rector, Larastan max, Deptrac, Pest, coverage)
- [ ] `make mutation` green on touched Domain paths
- [ ] No architecture rule violations (see `docs/engineering-guidelines.md`)
- [ ] Docs updated in this PR (README / `docs/` / ADR / OpenAPI)
- [ ] Migrations reversible, no long table locks (`CONCURRENTLY` where needed)
- [ ] No secrets in code or logs; logs structured and carry `trace_id`
- [ ] Every admin screen touched here is tenant-scoped, with a test proving it
- [ ] Conventional Commits, atomic commits
- [ ] No `TODO`/`FIXME` without a linked issue

## Design principles

<!--
  The mechanical rules — final classes, no concrete parents in Domain,
  repository placement, interface size, one entry point per handler — are
  checked by tests/Architecture/DesignPrinciplesTest.php and Deptrac, and a
  red pipeline is the answer to those. What follows is the half no machine
  reads. See docs/engineering-guidelines.md and
  docs/adr/0018-design-principles.md.
-->

- [ ] Each new class has one reason to change
- [ ] Every new abstraction answers a need that exists today (rule of three), not one expected later
- [ ] Any pattern used is on the approved map in `docs/engineering-guidelines.md` — or this PR says which it is not, and why
- [ ] Behaviour was extended by adding a class, not by editing existing branching logic
- [ ] Every implementation of a port runs the same contract tests as its siblings
- [ ] The module's complexity budget is respected (thin layers for Tenancy and Webhooks; the read side may use the query builder in a dedicated read class)
- [ ] Domain logic was written test-first, and its mutation score still holds

Simpler alternative considered and rejected because:
<!-- One sentence. "None — this is the simplest thing that holds the invariant" is a valid answer. -->

## Trade-offs accepted

<!-- What this deliberately does not do, and what would need to change to do it. -->
