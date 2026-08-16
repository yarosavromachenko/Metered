<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriods;
use Metered\Invoicing\Application\Command\CloseSubscriptionPeriodsHandler;
use Metered\Invoicing\Application\Command\FinalizeInvoice;
use Metered\Invoicing\Application\Command\FinalizeInvoiceHandler;
use Metered\Invoicing\Domain\Exception\InvoiceTransitionRefused;
use Metered\Invoicing\Domain\Invoice\Invoice;
use Metered\Invoicing\Domain\Invoice\InvoiceLine;
use Metered\Invoicing\Domain\Invoice\InvoicePeriod;
use Metered\Invoicing\Domain\Invoice\InvoiceRepository;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Money\Money;
use Spatie\Fork\Fork;
use Tests\Support\CommittedRows;
use Tests\Support\InvoicingScenario;

/*
 * Real processes, real connections, real commits — see
 * tests/Concurrency/Shared/IdempotencyRaceTest.php for why nothing less
 * proves anything. Each test works in an organization of its own and removes
 * it afterwards.
 *
 * Each child moves its clock on by its own millisecond. A UUIDv7 generated
 * within one millisecond continues a sequence kept in process memory, and
 * forked children all inherit the same one: on a frozen clock they would
 * generate the same ids. Workers in production are started, not forked, and
 * their clocks move.
 */

/**
 * @param Closure(): mixed $work
 *
 * @return Closure(): mixed
 */
function inItsOwnMillisecond(InvoicingScenario $scenario, int $child, Closure $work): Closure
{
    return static function () use ($scenario, $child, $work): mixed {
        DB::purge(testConnection());
        $scenario->clock->modify(sprintf('+%d milliseconds', $child));

        return $work();
    };
}

const RACE_ACTOR = 'test:invoicing-race';

$scenario = null;

beforeEach(function () use (&$scenario): void {
    $scenario = InvoicingScenario::start('2026-01-31 14:00:00', 'race-' . bin2hex(random_bytes(4)));
});

afterEach(function () use (&$scenario): void {
    if ($scenario instanceof InvoicingScenario) {
        CommittedRows::purgeOrganization($scenario->tenant->organizationId, RACE_ACTOR);
    }
});

it('builds exactly one invoice when one period is closed by many workers at once', function () use (&$scenario): void {
    assert($scenario instanceof InvoicingScenario);
    $scenario->usage('2026-02-10T10:00:00Z', '1250');
    $scenario->at('2026-02-28 15:00:00');

    $closes = array_map(
        static fn(int $child): Closure => inItsOwnMillisecond($scenario, $child, static fn(): int => count(
            app(CloseSubscriptionPeriodsHandler::class)->handle(
                new CloseSubscriptionPeriods($scenario->tenant, $scenario->subscription->id, Actor::system(RACE_ACTOR)),
            ),
        )),
        range(1, 8),
    );

    /** @var list<int> $built */
    $built = Fork::new()->run(...$closes);

    expect(array_sum($built))->toBe(1)
        ->and(DB::table('invoices')->where('subscription_id', $scenario->subscription->id->value)->count())->toBe(1)
        ->and(DB::table('invoices')->where('subscription_id', $scenario->subscription->id->value)->value('number'))->toBe(1)
        ->and(DB::table('ledger_transactions')->where('organization_id', $scenario->tenant->organizationId->value)->count())->toBe(1);
});

it('numbers fifty invoices finalized at once without a gap or a duplicate, even when some roll back', function () use (&$scenario): void {
    assert($scenario instanceof InvoicingScenario);
    $ids = app(IdentifierGenerator::class);
    $repository = app(InvoiceRepository::class);
    $drafts = [];

    // Fifty monthly periods of one subscription, each a draft of 29.00. Every
    // fifth is discarded first: finalizing it takes a number and then fails,
    // and the number must come back.
    foreach (range(0, 49) as $month) {
        $start = new DateTimeImmutable(sprintf('2026-01-31T14:00:00Z +%d months', $month));
        $period = InvoicePeriod::between($start, $start->modify('+1 month'));
        $draft = Invoice::draft($ids->generate(), $scenario->tenant, $scenario->customer->id, $scenario->subscription->id, 'EUR', $period, [
            InvoiceLine::fixed($ids->generate(), 'Flat fee', Money::ofMinorUnits(2900, 'EUR'), $period, []),
        ], $scenario->clock->now());

        DB::transaction(static fn(): bool => $repository->add($draft));

        if ($month % 5 === 4) {
            DB::transaction(static fn() => $repository->save($draft->discard($scenario->clock->now())));
        }

        $drafts[] = $draft->id;
    }

    $finalizations = array_map(
        static fn(Uuid $id, int $child): Closure => inItsOwnMillisecond($scenario, $child, static function () use ($scenario, $id): int {
            try {
                return app(FinalizeInvoiceHandler::class)->handle(new FinalizeInvoice($scenario->tenant, $id, Actor::system(RACE_ACTOR)))->number->sequence ?? 0;
            } catch (InvoiceTransitionRefused) {
                // A discarded draft: the number it took went back on rollback.
                return 0;
            }
        }),
        $drafts,
        array_keys($drafts),
    );

    /** @var list<int> $numbers */
    $numbers = Fork::new()->run(...$finalizations);
    $assigned = array_values(array_filter($numbers, static fn(int $number): bool => $number > 0));
    sort($assigned);

    expect($assigned)->toBe(range(1, 40))
        ->and(DB::table('invoices')->where('organization_id', $scenario->tenant->organizationId->value)->whereNotNull('number')->orderBy('number')->pluck('number')->all())->toBe(range(1, 40))
        ->and(DB::table('document_sequences')->where('organization_id', $scenario->tenant->organizationId->value)->value('last_number'))->toBe(40);
});
