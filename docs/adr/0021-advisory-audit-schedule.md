# 0021. Advisory audit runs weekly and does not block pull requests

- **Status:** Accepted
- **Date:** 2026-11-06

## Context

`composer audit --locked` was a blocking step of the `Security` job. It fails
on any advisory in the lock file, including one published the day before, for
a package the application may never reach through the affected code path. The
Symfony security release of 2026-11-04 published twelve advisories at once and
turned a green release branch red without a single change to it.

Dependencies are taken at their current versions and moved when there is a
reason to move them. Releases of the packages in use appear every week, so a
gate that fails on the newest advisory forces an update on that day.

## Decision

- In the pull request pipeline the `composer audit` step has
  `continue-on-error: true`. The advisories stay in the job log and as a
  warning on the run.
- The workflow `dependency-audit.yml` runs `composer audit --locked` every
  Monday and on demand. A red run is the signal to update.
- Trivy (filesystem and image) stays a blocking step.
- `make security` stays strict: it is the local check before a release.

## Consequences

- A pull request can merge with a lock file that has a known advisory. The
  weekly run and the release routine are what catch it.
- The scheduled workflow runs from the default branch only.
- Updating a dependency becomes a decision, not a reaction to a red build.

## Alternatives

- Keep the step blocking and update on every advisory. Rejected: the update
  lands in whichever branch is open, together with its regression risk.
- Ignore single advisories with `composer audit --ignore`. Rejected: the list
  grows by hand and hides the same information the weekly run shows.
- Remove `composer audit` from the pipeline. Rejected: Trivy covers high and
  critical findings only, and nothing would report the rest.
