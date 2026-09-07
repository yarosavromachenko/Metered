<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Metered\Simulation\Infrastructure\Queue\SeedDemoTenantJob;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Presentation\Filament\Auth\RegisterTenant;

it('queues a seed of the visitor\'s own tenant as they sign up, with a key that reaches it', function (): void {
    Queue::fake();

    Livewire::test(RegisterTenant::class)
        ->set('data.name', 'Visitor')
        ->set('data.email', 'visitor@example.com')
        ->set('data.password', 'correct horse battery staple')
        ->set('data.passwordConfirmation', 'correct horse battery staple')
        ->set('data.organization', 'Visitor Labs')
        ->call('register')
        ->assertHasNoErrors();

    $organization = DB::table('organizations')->where('slug', 'visitor-labs')->value('id');

    Queue::assertPushed(SeedDemoTenantJob::class, static function (SeedDemoTenantJob $job) use ($organization): bool {
        $key = app(ApiKeyAuthenticator::class)->authenticate($job->token);

        return $job->organizationId === $organization
            && $key->tenant->organizationId->value === $organization;
    });

    // It carries a key: never in the clear on the queue.
    expect(class_implements(SeedDemoTenantJob::class))->toContain(ShouldBeEncrypted::class);
});
