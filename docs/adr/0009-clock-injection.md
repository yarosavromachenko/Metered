# 0009. Time through an injected clock

- **Status:** Accepted
- **Date:** 2026-05-30

## Context

Almost every rule in this system is about time: when a period ends, whether an
event is late, when a retry is due, whether a key has expired. Code that calls
`now()` directly can only be tested at the moment the test happens to run, which
is why "the 31st" bugs reach production — nobody runs the suite on the 31st.

Laravel offers `Carbon::setTestNow()`, which works by mutating global state. It
is effective and it is also a global variable: under Octane, one request's
frozen time can leak into the next, and parallel tests interfere.

## Decision

Domain and application code depend on `Psr\Clock\ClockInterface` and nothing
else. Infrastructure provides a system clock; tests inject
`Symfony\Component\Clock\MockClock`.

Calls to `now()`, `Carbon::now()`, `time()` and `date()` are banned outside
infrastructure adapters, enforced by an architecture test.

Time is stored and computed exclusively in UTC, as `timestamptz`. Any local-time
presentation happens at the edge.

## Consequences

Time becomes an argument, so a test can place itself on 29 February 2028 or one
second before a grace window closes, deterministically and in parallel.

Nothing leaks between requests under Octane, because there is no global to leak —
the clock is a scoped dependency.

Working exclusively in UTC removes daylight-saving arithmetic from period
calculations entirely. A period boundary is a fixed instant, not a wall-clock
time that might occur twice in a year.

The cost is that the clock must be threaded through constructors into anything
time-dependent, which is visible in the code and occasionally tedious. It also
means resisting `now()` in a quick fix, which is why the ban is mechanical rather
than cultural.

## Alternatives considered

**`Carbon::setTestNow()`.** Free and already available. Rejected for the global
state: Octane leakage and parallel-test interference are real, and the leak is
the kind that produces an intermittent failure nobody can reproduce.

**A static `Clock::now()` facade.** More convenient to call, still global, and it
hides the dependency from the constructor — so a class's reliance on time becomes
invisible in its signature.

**Passing a timestamp into every method.** Explicit, and it spreads the parameter
through every call site until the signatures are unreadable. The clock is the
same idea with one dependency instead of a parameter everywhere.


## Accepted in M0

The ban is mechanical: `now()`, `today()`, `time()` and `date()` are in the
banned-helper list checked by the architecture suite for every module's domain
and application layer, and `Carbon` is refused there outright. The container
sets `date.timezone = UTC` so that a machine's local timezone can never leak
into a result.

## Amended in M7: a clock the demo can move

`sim:time-travel` moves the demo's clock forward so a reviewer can watch a
month of periods close in a minute (plan §10). Every process of the stack —
the app, Horizon's workers, the consumer, the scheduler, a console command —
has to agree on the new time, or a period closes in one while another refuses
its usage as too new. So the offset lives in the shared cache
(`ClockOffset`), and in the `local` and `demo` environments the clock is a
`TravellingClock`: the system clock plus that offset, read again at most once
a second.

Everywhere else the binding is the plain `SystemClock` and there is nothing to
move — an offset left in a production cache changes nothing. Tests bind a
`MockClock` as before. The clock only moves forward: everything written while
it was ahead carries the later time, and moving back would make "now" precede
invoices already built.
