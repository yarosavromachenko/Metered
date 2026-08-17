<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Application\Command\FinalizeInvoice;
use Metered\Invoicing\Application\Command\FinalizeInvoiceHandler;
use Metered\Invoicing\Application\Command\InvoiceNotFound;
use Metered\Invoicing\Application\Command\PayInvoice;
use Metered\Invoicing\Application\Command\PayInvoiceHandler;
use Metered\Invoicing\Application\Command\PaymentDeclined;
use Metered\Invoicing\Application\Command\VoidInvoice;
use Metered\Invoicing\Application\Command\VoidInvoiceHandler;
use Metered\Invoicing\Application\Payment\PaymentGateway;
use Metered\Invoicing\Application\Payment\PaymentRequest;
use Metered\Invoicing\Application\Payment\PaymentResult;
use Metered\Invoicing\Domain\CreditNote\CreditNoteRepository;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Invoicing\Domain\Invoice\BillTo;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Invoicing\Domain\Ledger\Account;
use Metered\Invoicing\Domain\Ledger\Ledger;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Money\Money;
use Metered\Tenancy\Domain\Role;
use Tests\Support\InvoicingScenario;
use Tests\Support\TenantFactory;

/**
 * The scenario's first period, closed: INV-000001 for 41.50 EUR.
 */
function finalizedFirstPeriod(InvoicingScenario $scenario): Invoice
{
    $scenario->usage('2026-02-01T10:00:00Z', '1250');

    [$invoice] = app(CloseSubscriptionPeriodsHandler::class)->handle(
        new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system('test')),
    );

    return $invoice;
}

function draftFirstPeriod(InvoicingScenario $scenario): Invoice
{
    $period = InvoicePeriod::between(new DateTimeImmutable('2026-01-31T14:00:00Z'), new DateTimeImmutable('2026-02-28T14:00:00Z'));
    $draft = Invoice::draft(
        app(IdentifierGenerator::class)->generate(),
        $scenario->tenant,
        $scenario->customer->id,
        new BillTo('cus_4471', 'North Wind Ltd'),
        $scenario->subscription->id,
        'EUR',
        $period,
        [InvoiceLine::fixed(app(IdentifierGenerator::class)->generate(), 'Flat fee', Money::ofMinorUnits(2900, 'EUR'), $period, [])],
        $scenario->clock->now(),
    );

    app(InvoiceRepository::class)->add($draft);

    return $draft;
}

/**
 * @return array<mixed>
 */
function outboxPayload(string $type): array
{
    $raw = DB::table('outbox_messages')->where('type', $type)->value('payload');

    return is_string($raw) ? (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
}

function balanceOf(InvoicingScenario $scenario, Account $account): string
{
    return (string) app(Ledger::class)->balance($scenario->tenant, $scenario->customer->id, $account, 'EUR');
}

it('collects a finalized invoice and books the cash against what was owed', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    $invoice = finalizedFirstPeriod($scenario);

    $paid = app(PayInvoiceHandler::class)->handle(new PayInvoice($scenario->tenant, $invoice->id, $scenario->operator));

    expect($paid->status)->toBe(InvoiceStatus::Paid)
        ->and(app(InvoiceRepository::class)->find($scenario->tenant, $invoice->id)?->status)->toBe(InvoiceStatus::Paid)
        ->and(balanceOf($scenario, Account::AccountsReceivable))->toBe('0.00 EUR')
        ->and(balanceOf($scenario, Account::Cash))->toBe('41.50 EUR')
        ->and(balanceOf($scenario, Account::Revenue))->toBe('41.50 EUR')
        ->and(DB::table('outbox_messages')->where('aggregate_id', $invoice->id->value)->orderBy('occurred_at')->pluck('type')->all())
        ->toBe(['invoice.finalized', 'invoice.paid'])
        ->and(outboxPayload('invoice.paid'))->toMatchArray(['number' => 'INV-000001', 'status' => 'paid', 'total_minor' => 4150, 'currency' => 'EUR'])
        ->and(outboxPayload('invoice.paid')['payment_reference'] ?? null)->toBeString()->toStartWith('fake_');
});

it('changes nothing when the provider declines', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    $invoice = finalizedFirstPeriod($scenario);
    app()->instance(PaymentGateway::class, new class implements PaymentGateway {
        public function charge(PaymentRequest $request): PaymentResult
        {
            return PaymentResult::declined('dcl_1', 'insufficient funds');
        }
    });

    expect(fn(): Invoice => app(PayInvoiceHandler::class)->handle(new PayInvoice($scenario->tenant, $invoice->id, $scenario->operator)))
        ->toThrow(PaymentDeclined::class, 'The payment was declined: insufficient funds')
        ->and(app(InvoiceRepository::class)->find($scenario->tenant, $invoice->id)?->status)->toBe(InvoiceStatus::Finalized)
        ->and(balanceOf($scenario, Account::AccountsReceivable))->toBe('41.50 EUR')
        ->and(balanceOf($scenario, Account::Cash))->toBe('0.00 EUR');
});

it('charges nobody for an invoice that is not open', function (): void {
    $scenario = InvoicingScenario::start();
    $draft = draftFirstPeriod($scenario);
    $gateway = new class implements PaymentGateway {
        public bool $charged = false;

        public function charge(PaymentRequest $request): PaymentResult
        {
            $this->charged = true;

            return PaymentResult::succeeded('never');
        }
    };
    app()->instance(PaymentGateway::class, $gateway);

    expect(fn(): Invoice => app(PayInvoiceHandler::class)->handle(new PayInvoice($scenario->tenant, $draft->id, $scenario->operator)))
        ->toThrow(InvoiceTransitionRefused::class, 'A draft invoice cannot be paid.')
        ->and($gateway->charged)->toBeFalse();
});

it('voids a finalized invoice with a credit note that reverses what it booked', function (): void {
    $scenario = InvoicingScenario::start()->at('2026-02-28 15:00:00');
    $invoice = finalizedFirstPeriod($scenario);

    $voided = app(VoidInvoiceHandler::class)->handle(new VoidInvoice($scenario->tenant, $invoice->id, 'Billed on the wrong plan', $scenario->operator));
    $note = app(CreditNoteRepository::class)->forInvoice($scenario->tenant, $invoice->id);

    expect($voided->status)->toBe(InvoiceStatus::Void)
        ->and((string) $voided->number)->toBe('INV-000001')
        ->and((string) $note?->number)->toBe('CN-000001')
        ->and((string) $note?->amount)->toBe('41.50 EUR')
        ->and($note?->reason)->toBe('Billed on the wrong plan')
        ->and($note?->issuedAt->format(DATE_ATOM))->toBe('2026-02-28T15:00:00+00:00')
        ->and($note?->customerId->value)->toBe($scenario->customer->id->value)
        ->and(balanceOf($scenario, Account::AccountsReceivable))->toBe('0.00 EUR')
        ->and(balanceOf($scenario, Account::Revenue))->toBe('0.00 EUR')
        ->and(outboxPayload('invoice.voided'))->toMatchArray(['credit_note_number' => 'CN-000001', 'status' => 'void'])
        ->and(DB::table('audit_log')->where('subject_id', $invoice->id->value)->orderBy('id')->pluck('action')->all())
        ->toBe(['invoice.finalized', 'invoice.voided']);
});

it('discards a draft without numbering, booking or crediting anything', function (): void {
    $scenario = InvoicingScenario::start();
    $draft = draftFirstPeriod($scenario);

    $discarded = app(VoidInvoiceHandler::class)->handle(new VoidInvoice($scenario->tenant, $draft->id, '', $scenario->operator));

    expect($discarded->status)->toBe(InvoiceStatus::Void)
        ->and($discarded->number)->toBeNull()
        ->and(app(CreditNoteRepository::class)->forInvoice($scenario->tenant, $draft->id))->toBeNull()
        ->and(DB::table('document_sequences')->count())->toBe(0)
        ->and(DB::table('ledger_transactions')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('subject_id', $draft->id->value)->pluck('action')->all())->toBe(['invoice.discarded']);
});

it('finalizes a draft left behind, from the panel', function (): void {
    $scenario = InvoicingScenario::start();
    $draft = draftFirstPeriod($scenario);

    $finalized = app(FinalizeInvoiceHandler::class)->handle(new FinalizeInvoice($scenario->tenant, $draft->id, $scenario->operator));

    expect((string) $finalized->number)->toBe('INV-000001')
        ->and(app(InvoiceRepository::class)->find($scenario->tenant, $draft->id)?->lines)->toEqual($draft->lines);
});

it('lets only those who move money finalize, pay or void', function (Role $role, Closure $action): void {
    $scenario = InvoicingScenario::start();
    $draft = draftFirstPeriod($scenario);
    $member = TenantFactory::member($scenario->tenant->organizationId, $role, 'someone@acme.test');

    /** @var Closure(InvoicingScenario, Invoice, Actor): Invoice $action */
    expect(static fn(): Invoice => $action($scenario, $draft, $member))->toThrow(PermissionDenied::class);
})->with([
    'viewer' => Role::Viewer,
    'admin' => Role::Admin,
])->with([
    'finalize' => [static fn(InvoicingScenario $s, Invoice $i, Actor $a): Invoice => app(FinalizeInvoiceHandler::class)->handle(new FinalizeInvoice($s->tenant, $i->id, $a))],
    'pay' => [static fn(InvoicingScenario $s, Invoice $i, Actor $a): Invoice => app(PayInvoiceHandler::class)->handle(new PayInvoice($s->tenant, $i->id, $a))],
    'void' => [static fn(InvoicingScenario $s, Invoice $i, Actor $a): Invoice => app(VoidInvoiceHandler::class)->handle(new VoidInvoice($s->tenant, $i->id, 'x', $a))],
]);

it('finds no invoice of another tenant', function (): void {
    $scenario = InvoicingScenario::start();
    $draft = draftFirstPeriod($scenario);
    $rival = TenantFactory::tenant('north-wind');
    $owner = TenantFactory::member($rival->organizationId);

    app(VoidInvoiceHandler::class)->handle(new VoidInvoice($rival->tenant(), $draft->id, 'x', $owner));
})->throws(InvoiceNotFound::class);
