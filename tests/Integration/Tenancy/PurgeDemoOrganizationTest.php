<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Application\Command\VoidInvoice;
use Metered\Invoicing\Application\Command\VoidInvoiceHandler;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Command\NotADemoOrganization;
use Metered\Tenancy\Application\Command\PurgeDemoOrganization;
use Metered\Tenancy\Application\Command\PurgeDemoOrganizationHandler;
use Metered\Tenancy\Application\Command\TenantNotFound;
use Metered\Tenancy\Domain\Role;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InvoicingScenario;
use Tests\Support\TenantFactory;

/**
 * Every table a tenant can have rows in, by the column that finds them.
 *
 * @var list<string>
 */
const TENANT_TABLES = [
    'api_keys', 'projects', 'organization_members',
    'meters', 'customers', 'plans', 'plan_versions', 'prices', 'subscriptions', 'subscription_phases',
    'usage_events', 'usage_aggregates', 'usage_event_rejections',
    'invoices', 'invoice_lines', 'credit_notes', 'ledger_transactions', 'ledger_entries', 'document_sequences',
    'webhook_endpoints',
];

/**
 * A tenant with something in every one of those tables: a closed period whose
 * invoice was voided — lines, ledger, a credit note, numbering — an event, a
 * rejection, and a webhook endpoint.
 */
function tenantWithHistory(string $slug, bool $demo): InvoicingScenario
{
    $organization = TenantFactory::organization($slug, $demo);
    $project = TenantFactory::project($organization);
    TenantFactory::apiKey($project);
    $scenario = InvoicingScenario::in($project, new MockClock('2026-01-31 14:00:00', 'UTC'), 'cus_' . $slug);

    $scenario->usage('2026-02-01T10:00:00Z', '1250');
    $scenario->at('2026-02-28 15:00:00');
    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')),
    );
    app(VoidInvoiceHandler::class)->handle(new VoidInvoice($scenario->tenant, $invoice->id, 'Wrong plan', $scenario->operator));

    $ids = app(IdentifierGenerator::class);
    DB::table('usage_events')->insert([
        'id' => $ids->generate()->value,
        'organization_id' => $scenario->tenant->organizationId->value,
        'project_id' => $scenario->tenant->projectId->value,
        'event_id' => 'evt_' . $slug,
        'customer_id' => $scenario->customer->id->value,
        'meter_id' => $scenario->meter->id->value,
        'meter_code' => 'api.requests',
        'customer_ref' => 'cus_' . $slug,
        'quantity' => '1250',
        'occurred_at' => '2026-02-01 10:00:00+00',
        'received_at' => '2026-02-01 10:00:01+00',
        'properties' => '{}',
    ]);
    DB::table('usage_event_rejections')->insert([
        'id' => $ids->generate()->value,
        'organization_id' => $scenario->tenant->organizationId->value,
        'project_id' => $scenario->tenant->projectId->value,
        'event_id' => 'evt_unknown',
        'reason' => 'unknown_meter',
        'detail' => 'No meter in this project answers to that code.',
        'payload' => '{}',
        'rejected_at' => '2026-02-01 10:00:00+00',
    ]);

    app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
        $scenario->tenant,
        'https://hooks.example.com/' . $slug,
        '',
        ['invoice.voided'],
        TenantFactory::member($project->organizationId, Role::Admin, 'admin@' . $slug . '.test'),
    ));

    return $scenario;
}

/**
 * @return array<string, int>
 */
function rowsOf(Uuid $organizationId): array
{
    $counts = [];

    foreach (TENANT_TABLES as $table) {
        $counts[$table] = DB::table($table)->where('organization_id', $organizationId->value)->count();
    }

    return $counts;
}

function purge(Uuid $organizationId): void
{
    app(PurgeDemoOrganizationHandler::class)->handle(new PurgeDemoOrganization($organizationId, Actor::system('test')));
}

it('removes a demo tenant from every table, money history included, and nothing of anyone else', function (): void {
    $demo = tenantWithHistory('visitor', demo: true);
    $real = tenantWithHistory('customer', demo: false);
    $before = rowsOf($real->tenant->organizationId);

    // The fixture has to be worth something: every table holds a row of both.
    expect(array_filter(rowsOf($demo->tenant->organizationId), static fn(int $rows): bool => $rows === 0))->toBe([])
        ->and(array_filter($before, static fn(int $rows): bool => $rows === 0))->toBe([]);

    purge($demo->tenant->organizationId);

    expect(array_filter(rowsOf($demo->tenant->organizationId), static fn(int $rows): bool => $rows > 0))->toBe([])
        ->and(DB::table('organizations')->where('id', $demo->tenant->organizationId->value)->exists())->toBeFalse()
        ->and(rowsOf($real->tenant->organizationId))->toBe($before);
});

it('deletes the accounts that belonged only to the demo, and keeps anyone who is a member elsewhere', function (): void {
    $demo = tenantWithHistory('visitor', demo: true);
    $elsewhere = TenantFactory::organization('elsewhere');
    $operator = TenantFactory::userIdOf($demo->operator);
    $owner = TenantFactory::userIdOf(TenantFactory::member($demo->tenant->organizationId, Role::Owner, 'owner@visitor.test'));
    DB::table('organization_members')->insert([
        'id' => app(IdentifierGenerator::class)->generate()->value,
        'organization_id' => $elsewhere->id->value,
        'user_id' => $owner->value,
        'role' => Role::Viewer->value,
        'created_at' => now(),
    ]);

    purge($demo->tenant->organizationId);

    expect(DB::table('users')->where('id', $operator->value)->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $owner->value)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('user_id', $owner->value)->pluck('organization_id')->all())->toBe([$elsewhere->id->value]);
});

it('records the purge in the audit log, and keeps what the log already said', function (): void {
    $demo = tenantWithHistory('visitor', demo: true);
    $entriesBefore = DB::table('audit_log')->count();

    purge($demo->tenant->organizationId);

    $entry = DB::table('audit_log')->where('action', 'organization.purged')->first();

    expect(DB::table('audit_log')->count())->toBe($entriesBefore + 1)
        ->and($entry?->subject_id)->toBe($demo->tenant->organizationId->value)
        ->and(json_decode(is_string($entry?->payload) ? $entry->payload : '{}', true))->toBe(['slug' => 'visitor', 'members' => 2, 'projects' => 1]);

    expect(Artisan::call('audit:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('Audit chain intact');
});

it('refuses to purge a tenant that was not created as a demo, and deletes nothing', function (): void {
    $real = tenantWithHistory('customer', demo: false);
    $before = rowsOf($real->tenant->organizationId);

    expect(static fn() => purge($real->tenant->organizationId))
        ->toThrow(NotADemoOrganization::class, 'not created as a demo');

    expect(rowsOf($real->tenant->organizationId))->toBe($before);
});

it('refuses an organization that does not exist', function (): void {
    purge(app(IdentifierGenerator::class)->generate());
})->throws(TenantNotFound::class, 'No organization');

dataset('append-only tables', [
    'ledger entries' => ['ledger_entries', 'the ledger is append-only'],
    'ledger transactions' => ['ledger_transactions', 'the ledger is append-only'],
    'credit notes' => ['credit_notes', 'credit notes are never changed or removed'],
    'invoice lines' => ['invoice_lines', 'invoice lines are never changed or removed'],
    'invoices' => ['invoices', 'void it instead'],
]);

it('has the database refuse a purge of a real tenant, whatever the transaction declares', function (string $table, string $refusal): void {
    $real = tenantWithHistory('customer', demo: false);

    expect(static fn() => DB::transaction(static function () use ($real, $table): void {
        DB::select("SELECT set_config('metered.purging_organization', ?, true)", [$real->tenant->organizationId->value]);
        DB::table($table)->where('organization_id', $real->tenant->organizationId->value)->delete();
    }))->toThrow(QueryException::class, $refusal);
})->with('append-only tables');

it('has the database refuse a demo tenant\'s history to anyone who has not declared the purge', function (string $table, string $refusal): void {
    $demo = tenantWithHistory('visitor', demo: true);

    expect(static fn() => DB::table($table)->where('organization_id', $demo->tenant->organizationId->value)->delete())
        ->toThrow(QueryException::class, $refusal);
})->with('append-only tables');

it('admits the declaration for the organization it names and no other', function (): void {
    $demo = tenantWithHistory('visitor', demo: true);
    $other = tenantWithHistory('neighbour', demo: true);

    expect(static fn() => DB::transaction(static function () use ($demo, $other): void {
        DB::select("SELECT set_config('metered.purging_organization', ?, true)", [$demo->tenant->organizationId->value]);
        DB::table('credit_notes')->where('organization_id', $other->tenant->organizationId->value)->delete();
    }))->toThrow(QueryException::class, 'credit notes are never changed or removed');
});

it('holds whether an organization is a demo fixed from the moment it is created', function (bool $demo): void {
    $organization = TenantFactory::organization('fixed', $demo);

    expect(static fn() => DB::table('organizations')->where('id', $organization->id->value)->update(['demo' => ! $demo]))
        ->toThrow(QueryException::class, 'stays that way');
})->with([true, false]);
