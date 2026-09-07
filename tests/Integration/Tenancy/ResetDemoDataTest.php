<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Application\Authentication\ApiKeyAuthenticator;
use Metered\Tenancy\Application\Contract\DemoDataRequested;
use Metered\Tenancy\Domain\Role;
use Metered\Tenancy\Domain\Scope;
use Metered\Tenancy\Presentation\Filament\Actions\ResetDemoDataAction;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InvoicingScenario;
use Tests\Support\PanelSession;

/**
 * @return array<string, int>
 */
function demoRows(string $organizationId): array
{
    $counts = [];

    foreach (['meters', 'customers', 'plans', 'subscriptions', 'usage_aggregates', 'invoices', 'invoice_lines', 'ledger_entries', 'document_sequences'] as $table) {
        $counts[$table] = DB::table($table)->where('organization_id', $organizationId)->count();
    }

    return $counts;
}

it('clears a demo tenant, keeps its people, projects and keys, and asks for it to be filled again', function (): void {
    Event::fake([DemoDataRequested::class]);
    $project = PanelSession::signIn('visitor', Role::Owner, demo: true);
    $scenario = InvoicingScenario::in($project, new MockClock('2026-01-31 14:00:00', 'UTC'));
    $scenario->usage('2026-02-01T10:00:00Z', '10');
    $scenario->at('2026-02-28 15:00:00');
    app(CloseSubscriptionPeriodsHandler::class)->handle(new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')));
    $organization = $project->organizationId->value;
    $members = DB::table('organization_members')->where('organization_id', $organization)->count();

    expect(array_filter(demoRows($organization), static fn(int $rows): bool => $rows > 0))->not->toBe([])
        ->and(ResetDemoDataAction::offered())->toBeTrue();

    ResetDemoDataAction::run();

    expect(array_filter(demoRows($organization), static fn(int $rows): bool => $rows > 0))->toBe([])
        ->and(DB::table('projects')->where('id', $project->id->value)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('organization_id', $organization)->count())->toBe($members)
        ->and(DB::table('audit_log')->where('action', 'organization.demo_reset')->where('subject_id', $organization)->exists())->toBeTrue();

    Event::assertDispatched(DemoDataRequested::class, static function (DemoDataRequested $request) use ($organization): bool {
        $key = app(ApiKeyAuthenticator::class)->authenticate($request->token);

        return $request->organizationId === $organization
            && $key->tenant->organizationId->value === $organization
            && $key->allows(Scope::Admin) && $key->allows(Scope::UsageWrite);
    });
});

it('is not offered outside a demo organization, nor to anyone who does not manage it', function (bool $demo, Role $role): void {
    PanelSession::signIn('acme', $role, $demo);

    expect(ResetDemoDataAction::offered())->toBeFalse();
})->with([
    'a real organization, its owner' => [false, Role::Owner],
    'a demo organization, a viewer' => [true, Role::Viewer],
    'a demo organization, a billing operator' => [true, Role::BillingOperator],
]);

it('refuses a viewer who calls it anyway, and clears nothing', function (): void {
    Event::fake([DemoDataRequested::class]);
    $project = PanelSession::signIn('visitor', Role::Viewer, demo: true);
    $scenario = InvoicingScenario::in($project, new MockClock('2026-01-31 14:00:00', 'UTC'));

    ResetDemoDataAction::run();

    expect(DB::table('subscriptions')->where('id', $scenario->subscription->id->value)->exists())->toBeTrue();
    Event::assertNotDispatched(DemoDataRequested::class);
});

it('refuses to clear a real organization, whoever asks', function (): void {
    Event::fake([DemoDataRequested::class]);
    $project = PanelSession::signIn('acme', Role::Owner);
    $scenario = InvoicingScenario::in($project, new MockClock('2026-01-31 14:00:00', 'UTC'));

    ResetDemoDataAction::run();

    expect(DB::table('subscriptions')->where('id', $scenario->subscription->id->value)->exists())->toBeTrue();
    Event::assertNotDispatched(DemoDataRequested::class);
});
