<?php

declare(strict_types=1);

use Metered\Usage\Domain\AcceptanceWindow;
use Metered\Usage\Domain\RejectionReason;

const NOW = '2026-09-22T12:00:00+00:00';

it('accepts an event that happened inside the window', function (string $occurredAt): void {
    expect(window()->reasonToReject(at($occurredAt), at(NOW)))->toBeNull();
})->with([
    'right now' => [NOW],
    'a minute ago' => ['2026-09-22T11:59:00+00:00'],
    'yesterday' => ['2026-09-21T12:00:00+00:00'],
    'six days and twenty-three hours ago' => ['2026-09-15T13:00:00+00:00'],
    // Clocks drift. A client a couple of minutes fast is a fact of life, not
    // an error, so the window is deliberately asymmetric rather than closed
    // at the present instant.
    'four minutes in the future' => ['2026-09-22T12:04:00+00:00'],
]);

it('accepts an event sitting exactly on either edge', function (string $occurredAt): void {
    expect(window()->reasonToReject(at($occurredAt), at(NOW)))->toBeNull();
})->with([
    'exactly seven days old' => ['2026-09-15T12:00:00+00:00'],
    'exactly five minutes ahead' => ['2026-09-22T12:05:00+00:00'],
]);

it('rejects an event older than the window, because its period is closed', function (): void {
    // Beyond this, the invoice for the period it belongs to has been finalized
    // and an event cannot change it (ADR-0010). Silently dropping it would
    // leave a tenant's totals short with nothing to look at.
    expect(window()->reasonToReject(at('2026-09-15T11:59:59+00:00'), at(NOW)))
        ->toBe(RejectionReason::TooOld);
});

it('rejects an event further ahead than a clock could plausibly drift', function (): void {
    expect(window()->reasonToReject(at('2026-09-22T12:05:01+00:00'), at(NOW)))
        ->toBe(RejectionReason::InTheFuture);
});

it('compares instants, not the offsets they arrived in', function (): void {
    // The same moment, written in another time zone.
    expect(window()->reasonToReject(at('2026-09-22T15:00:00+03:00'), at(NOW)))->toBeNull();
});

it('states the window it is enforcing, so the API reference can quote it', function (): void {
    expect(window()->maxAgeSeconds)->toBe(7 * 24 * 60 * 60)
        ->and(window()->maxDriftSeconds)->toBe(300);
});

function window(): AcceptanceWindow
{
    return AcceptanceWindow::of(maxAgeSeconds: 7 * 24 * 60 * 60, maxDriftSeconds: 300);
}

function at(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant);
}
