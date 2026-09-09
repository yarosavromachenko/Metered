<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Domain\Role;
use Tests\Support\TenantFactory;

function organizationExists(string $slug): bool
{
    return DB::table('organizations')->where('slug', $slug)->exists();
}

it('deletes every demo organization, the showcase included, and no real one', function (): void {
    config(['metered.demo.showcase' => 'northwind-cloud']);

    foreach (['northwind-cloud' => true, 'visitor' => true, 'customer' => false] as $slug => $demo) {
        $organization = TenantFactory::organization($slug, $demo);
        TenantFactory::project($organization);
        TenantFactory::member($organization->id, Role::Owner, $slug . '@example.com');
    }

    expect(Artisan::call('demo:reset', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 2 demo organization(s).');

    expect(organizationExists('northwind-cloud'))->toBeFalse()
        ->and(organizationExists('visitor'))->toBeFalse()
        ->and(organizationExists('customer'))->toBeTrue();
});

it('deletes nothing unless confirmed, and a script cannot confirm without --force', function (): void {
    TenantFactory::organization('visitor', demo: true);

    expect(Artisan::call('demo:reset', ['--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Nothing was deleted.');

    expect(organizationExists('visitor'))->toBeTrue();
});

it('has nothing to do on an instance without demos', function (): void {
    TenantFactory::organization('customer');

    expect(Artisan::call('demo:reset', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Purged 0 demo organization(s).');
});
