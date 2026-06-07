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

## Trade-offs accepted

<!-- What this deliberately does not do, and what would need to change to do it. -->
