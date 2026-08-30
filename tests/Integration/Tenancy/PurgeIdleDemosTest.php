<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Identity\UserAccounts;
use Metered\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\TenantFactory;

beforeEach(function (): void {
    app()->instance(ClockInterface::class, new MockClock('2026-03-01 12:00:00', 'UTC'));
});

function moveTo(string $instant): void
{
    $clock = app(ClockInterface::class);
    assert($clock instanceof MockClock);
    $clock->modify($instant);
}

function sweep(): string
{
    expect(Artisan::call('tenancy:purge-idle-demos'))->toBe(0);

    return Artisan::output();
}

/**
 * A demo organization created on 1 March whose one member last signed in
 * when the test says, or never.
 */
function demoSignedInAt(string $slug, ?string $signedInAt, bool $demo = true): Uuid
{
    $organization = TenantFactory::organization($slug, $demo);
    TenantFactory::project($organization);
    $member = TenantFactory::member($organization->id, Role::Owner, $slug . '@example.com');

    if ($signedInAt !== null) {
        app(UserAccounts::class)->recordSignIn(TenantFactory::userIdOf($member), new DateTimeImmutable($signedInAt));
    }

    return $organization->id;
}

function stillThere(Uuid $organizationId): bool
{
    return DB::table('organizations')->where('id', $organizationId->value)->exists();
}

it('purges the demos nobody has signed in to for a week, and nothing else', function (): void {
    $gone = demoSignedInAt('gone', '2026-03-02 09:00:00');
    $recent = demoSignedInAt('recent', '2026-03-06 09:00:00');
    $never = demoSignedInAt('never', null);
    $real = demoSignedInAt('real', '2026-03-02 09:00:00', demo: false);

    moveTo('2026-03-10 12:00:00');

    expect(sweep())->toContain('Purged 2 idle demo organization(s).');

    expect(stillThere($gone))->toBeFalse()
        ->and(stillThere($never))->toBeFalse()
        ->and(stillThere($recent))->toBeTrue()
        ->and(stillThere($real))->toBeTrue();
});

it('keeps a demo while any one of its members is still coming back', function (): void {
    $organization = demoSignedInAt('shared', '2026-03-01 13:00:00');
    $colleague = TenantFactory::member($organization, Role::Admin, 'colleague@example.com');
    app(UserAccounts::class)->recordSignIn(TenantFactory::userIdOf($colleague), new DateTimeImmutable('2026-03-09 08:00:00'));

    moveTo('2026-03-10 12:00:00');
    sweep();

    expect(stillThere($organization))->toBeTrue();
});

it('counts a week to the microsecond, from the last sign-in', function (string $now, bool $purged): void {
    $organization = demoSignedInAt('edge', '2026-03-02 12:00:00');

    moveTo($now);
    sweep();

    expect(stillThere($organization))->toBe(! $purged);
})->with([
    'exactly a week later' => ['2026-03-09 12:00:00', false],
    'a microsecond past it' => ['2026-03-09 12:00:00.000001', true],
]);

it('does not purge a demo created less than a week ago that nobody has used yet', function (): void {
    moveTo('2026-03-05 12:00:00');
    $organization = TenantFactory::organization('fresh', demo: true);

    moveTo('2026-03-10 12:00:00');
    expect(sweep())->toContain('Purged 0');

    expect(stillThere($organization->id))->toBeTrue();
});

it('runs every day on the scheduler', function (): void {
    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())->toContain('tenancy:purge-idle-demos');
});
