<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Shared\Domain\Access\Actor;
use Metered\Tenancy\Domain\Scope;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Symfony\Component\Clock\MockClock;
use Tests\Support\InvoicingScenario;
use Tests\Support\TenantFactory;

/**
 * A project with one closed period and an admin key to read it with.
 *
 * @param list<Scope> $scopes
 *
 * @return array{invoice: Invoice, headers: array<string, string>}
 */
function invoicedProject(string $slug = 'acme', array $scopes = [Scope::Admin]): array
{
    $project = TenantFactory::tenant($slug);
    ['secret' => $secret] = TenantFactory::apiKey($project, $scopes);
    $clock = new MockClock('2026-01-31 14:00:00', 'UTC');
    $scenario = InvoicingScenario::in($project, $clock, 'cus_' . $slug);
    $scenario->usage('2026-02-01T10:00:00Z', '1250');
    $clock->modify('2026-02-28 15:00:00');

    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')),
    );

    return ['invoice' => $invoice, 'headers' => ['Authorization' => 'Bearer ' . $secret->reveal()]];
}

it('lists the project’s invoices, newest first, filtered as asked', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject();
    invoicedProject('north-wind');

    getJson('/api/v1/invoices', $headers)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invoice->id->value)
        ->assertJsonPath('data.0.number', 'INV-000001')
        ->assertJsonPath('data.0.status', 'finalized')
        ->assertJsonPath('data.0.customer_ref', 'cus_acme')
        ->assertJsonPath('data.0.total', ['amount' => 4150, 'currency' => 'EUR']);

    getJson('/api/v1/invoices?status=paid', $headers)->assertOk()->assertJsonCount(0, 'data');
    getJson('/api/v1/invoices?customer_ref=cus_acme&limit=1', $headers)->assertOk()->assertJsonCount(1, 'data');
    getJson('/api/v1/invoices?status=overdue', $headers)->assertUnprocessable();
});

it('shows one invoice with its lines and their working', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject();

    getJson('/api/v1/invoices/' . $invoice->id->value, $headers)->assertOk()
        ->assertJsonPath('bill_to', ['reference' => 'cus_acme', 'name' => 'Customer cus_acme'])
        ->assertJsonPath('lines.1.kind', 'usage')
        ->assertJsonPath('lines.1.meter_code', 'api.requests')
        ->assertJsonPath('lines.1.quantity', '1250.000000')
        ->assertJsonPath('lines.1.amount', ['amount' => 1250, 'currency' => 'EUR'])
        ->assertJsonPath('lines.1.calculation', ['1250 × 0.01 EUR = 12.50 EUR'])
        ->assertJsonPath('total', ['amount' => 4150, 'currency' => 'EUR'])
        ->assertJsonPath('credit_note', null);
});

it('answers 404 for another project’s invoice, and for an id that is not one', function (string $path): void {
    ['headers' => $headers] = invoicedProject();
    ['invoice' => $theirs] = invoicedProject('north-wind');

    getJson(str_replace('{theirs}', $theirs->id->value, $path), $headers)->assertNotFound();
})->with([
    'another project' => ['/api/v1/invoices/{theirs}'],
    'another project’s PDF' => ['/api/v1/invoices/{theirs}/pdf'],
    'not an id' => ['/api/v1/invoices/INV-000001'],
]);

it('serves the invoice as a PDF', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject();

    $response = get('/api/v1/invoices/' . $invoice->id->value . '/pdf', $headers)->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="INV-000001.pdf"');

    expect((string) $response->baseResponse->getContent())->toStartWith('%PDF-');
});

it('collects payment once, however often the request is retried', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject();
    $key = (string) Str::uuid();

    postJson('/api/v1/invoices/' . $invoice->id->value . '/pay', [], $headers + ['Idempotency-Key' => $key])->assertOk()
        ->assertJsonPath('status', 'paid');
    postJson('/api/v1/invoices/' . $invoice->id->value . '/pay', [], $headers + ['Idempotency-Key' => $key])->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true');
    postJson('/api/v1/invoices/' . $invoice->id->value . '/pay', [], $headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'A paid invoice cannot be paid.');
});

it('voids with a credit note, and wants a reason for it', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject();

    postJson('/api/v1/invoices/' . $invoice->id->value . '/void', [], $headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable();

    postJson('/api/v1/invoices/' . $invoice->id->value . '/void', ['reason' => 'Duplicate'], $headers + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()
        ->assertJsonPath('status', 'void')
        ->assertJsonPath('credit_note.number', 'CN-000001')
        ->assertJsonPath('credit_note.amount', ['amount' => 4150, 'currency' => 'EUR'])
        ->assertJsonPath('credit_note.reason', 'Duplicate');
});

it('keeps invoices from a key that only reports usage', function (): void {
    ['invoice' => $invoice, 'headers' => $headers] = invoicedProject('acme', [Scope::UsageWrite]);

    getJson('/api/v1/invoices', $headers)->assertForbidden();
    postJson('/api/v1/invoices/' . $invoice->id->value . '/pay', [], $headers + ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
});
