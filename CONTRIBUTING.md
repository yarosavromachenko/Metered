# Contributing

This is a portfolio repository with a single maintainer, but it is run like a
team project on purpose: every rule here exists because it is enforced in CI.

## Prerequisites

Docker and Docker Compose are the only hard requirements — PHP, PostgreSQL, and
Redis all run in containers. `make up` builds everything.

## The loop

```bash
make up          # build and start the stack
make check       # style, static analysis, architecture, tests — what CI runs
make fix         # apply Pint and Rector fixes
```

Never push without a green `make check`. If a gate fails for a reason you believe
is wrong, fix the gate in its own commit with an explanation — do not weaken it.

## Branches and commits

- One branch per milestone or per logical change: `m3/usage-ingestion`,
  `fix/outbox-relay-lag`.
- [Conventional Commits](https://www.conventionalcommits.org/):
  `feat(billing): add volume pricing model`, `fix(usage): ack after commit, not before`.
- Atomic commits. A commit that mixes a refactor with a behaviour change is two
  commits.

## Definition of done

The pull request template repeats this as a checklist:

- [ ] Tests written first for domain logic; all relevant levels covered
- [ ] `make check` green: Pint, Rector, Larastan max, Deptrac, Pest, coverage; `make mutation` green for touched domain paths
- [ ] No architecture rule violations (`docs/engineering-guidelines.md`)
- [ ] Docs updated in the same PR (README / `docs/` / ADR / OpenAPI)
- [ ] Migrations are reversible and do not hold long locks (`CONCURRENTLY` on big tables)
- [ ] No secrets in code or logs; logs structured and carry `trace_id`
- [ ] Conventional Commits, atomic commits

## Architecture decisions

Anything non-obvious gets an ADR before the code:

```bash
cp docs/adr/0000-template.md docs/adr/00NN-short-title.md
```

Fill in Context, Decision, Consequences, and Alternatives. Alternatives is not
optional — an ADR that lists no rejected option is a note, not a decision.

## Adding a dependency

Justify it in the PR description: what it replaces, what it costs, why writing it
by hand is worse. Architectural dependencies also need an ADR.

## Code style

See [`docs/engineering-guidelines.md`](docs/engineering-guidelines.md). Short
version: `strict_types` everywhere, `final` by default, no `float` for money, no
framework inside `Domain`, no `now()` outside adapters.
