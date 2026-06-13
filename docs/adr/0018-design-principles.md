# 0018. Design principles: earn every abstraction

- **Status:** Accepted
- **Date:** 2026-06-13
- **Supersedes:** —
- **Superseded by:** —

## Context

The boundaries of this codebase are already machine-checked. Deptrac says which
namespace may reference which, `tests/Architecture/` says how the code inside a
namespace may be written, and `docs/engineering-guidelines.md` explains both.
None of that says anything about the question that actually decides whether this
code is pleasant to read: *how much structure is enough*.

Both failure modes are live here. Under-structured is the ordinary Laravel one —
business rules in controllers, money in floats, a job that knows about HTTP.
Over-structured is the one this project is more exposed to, because it is a
portfolio piece: patterns applied to be seen applying them. A command bus in
front of six handlers, an interface per class, a factory that returns `new
$this->class(...)`. Both read badly, and the second is harder to argue with
because every individual step has a textbook name.

M1 closed with 49 files in `src/`, and nearly all of them are value objects and
ports — the part of a system where structure is cheap. M2 through M9 add the
parts where it is not: pricing strategies, an invoice state machine, a payment
gateway adapter, a webhook delivery pipeline with a breaker. That is where a
pattern gets reached for because it is familiar rather than because the problem
asked for it, and where the decision needs to already be written down.

The reviewer this repository is written for spends about ten minutes in it.

## Decision

The `Design principles` section of `docs/engineering-guidelines.md` is the
standard: a guiding rule (complexity must be earned, and the reason stated),
SOLID read in terms of this system, a KISS/YAGNI budget per module, a closed map
of approved patterns, and the TDD levels. A pattern outside that map needs an
ADR or a note in the pull request.

The half of it a machine can check is checked, in
`tests/Architecture/DesignPrinciplesTest.php`:

- Every concrete class under `src/` is `final`. Interfaces, enums and abstract
  classes are the extension points; everything else is closed, so extension
  happens by composition or by a new class rather than by subclassing something
  that did not ask to be subclassed.
- No class in a `Domain` namespace extends a concrete parent. The exception
  ladder is exempt, because PHP's own `Throwable` hierarchy is concrete all the
  way down and `DomainException extends RuntimeException` is the only way to
  write it.
- A `*Repository` interface lives in `Domain`; a concrete `*Repository` lives in
  `Infrastructure`.
- No interface declares more than five methods — the widest port today,
  `IdempotencyStore`, declares four. This is the "no god services" rule with a
  number attached.
- A `*Handler` exposes exactly one public entry point, which is `handle` or
  `__invoke` — one use case, one way in.

Deptrac already carries the rest of the **D**: `Domain` and `Application` depend
on abstractions, `Infrastructure` implements them, and one module reaches
another only through `Application\Contract\*`.

What is left is judgment, and judgment goes in `.github/PULL_REQUEST_TEMPLATE.md`
as questions a reviewer must answer in words: whether a new abstraction has a
present-day need, whether a new pattern is on the map and solves a real problem
here, whether the same contract tests run against every implementation of a
port, and whether the thing could be simpler without losing an invariant.

Two of the automated rules — repositories and handlers — match nothing in `src/`
today. They are written now because the milestone that introduces the first
repository is also the milestone where the convention is easiest to break.

The TDD half of the section is unchanged in substance from how this repository
already works, with one wording correction folded in: the mutation bar is
measured by Pest's mutation plugin, which replaced Infection earlier in M1. The
bar itself — Domain tests are judged by mutation score, not by raw coverage —
stands.

## Consequences

The cost is paid in four places.

`final` everywhere means a test can never subclass production code to stub one
method. That is the point, but it is a real constraint: every seam has to be an
interface, and a class that is awkward to fake is a class with a missing port.
`tests/Support/` already works this way.

A closed pattern map turns every genuinely novel choice into paperwork. The
honest expectation is that this is right most of the time and wrong occasionally,
and the escape hatch is one paragraph in a PR, not a rewrite of this ADR.

An interface method limit of five is a number, not a truth. It will eventually
block a port that legitimately needs six, and the answer then is to split the
port or to change the number here — deliberately, in a superseding ADR, rather
than by adding an exception list to the test.

Two rules pass vacuously today. A green test that asserts nothing about the
current code proves only that nothing is broken yet; it earns its place in M2.

What it buys: the reason an abstraction exists is written down while it is being
introduced, when the reason is still known. A reader can predict where a thing
lives from its name. And the review conversation has vocabulary — "which entry
on the map is this?" is a shorter argument than "this feels like a lot".

## Alternatives considered

**Leave it to review discipline.** No document, no tests, judgement applied per
pull request. Rejected because the discipline of a solo project is a mood, not a
process; the whole reason the layering rules are in Deptrac rather than in a
README is that the README version was already being forgotten.

**Enforce more of it statically** — a custom PHPStan rule set, or an
ArchUnit-style DSL describing every principle. Rejected on cost: the rules that
remain are the ones about *why* a class exists, and no static analyser reads
intent. The effort buys a longer test suite and no more safety.

**Adopt a canonical DDD template wholesale** — repository per aggregate, factory
per aggregate, domain service per operation, application service per use case.
Rejected because it prescribes exactly the inflation this ADR exists to prevent:
it would put a repository in front of the read side, where a query builder in a
dedicated read class is both simpler and faster.

**KISS with no pattern map at all.** Rejected because "keep it simple" gives a
reviewer no vocabulary. The map is not there to authorise patterns; it is there
so that naming one is the start of an argument rather than the end of it.
