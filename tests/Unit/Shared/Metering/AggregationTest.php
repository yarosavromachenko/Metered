<?php

declare(strict_types=1);

use Metered\Shared\Domain\Metering\Aggregation;
use Metered\Shared\Domain\Quantity\Quantity;

it('folds an event into a running total the way its meter says', function (
    Aggregation $aggregation,
    string $running,
    string $event,
    string $expected,
): void {
    $folded = $aggregation->fold(Quantity::fromString($running), Quantity::fromString($event));

    expect((string) $folded)->toBe($expected);
})->with([
    'sum adds' => [Aggregation::Sum, '10', '2.5', '12.500000'],
    'sum from nothing' => [Aggregation::Sum, '0', '2.5', '2.500000'],
    // A count meter counts events, so what the event carries is irrelevant —
    // including a zero, which still happened.
    'count ignores the quantity' => [Aggregation::Count, '3', '99', '4.000000'],
    'count counts a zero event' => [Aggregation::Count, '0', '0', '1.000000'],
    'max keeps the larger' => [Aggregation::Max, '10', '2.5', '10.000000'],
    'max takes the larger' => [Aggregation::Max, '2.5', '10', '10.000000'],
    'max from nothing' => [Aggregation::Max, '0', '0.25', '0.250000'],
]);

it('folds in any order, because a stream delivers in none', function (Aggregation $aggregation): void {
    $fold = static function (string ...$quantities) use ($aggregation): string {
        $running = Quantity::zero();

        foreach ($quantities as $quantity) {
            $running = $aggregation->fold($running, Quantity::fromString($quantity));
        }

        return (string) $running;
    };

    // The property the exactly-once effect rests on (ADR-0004): redelivery and
    // out-of-order delivery only stay harmless while folding is commutative.
    expect($fold('3', '1.5', '7', '0.25'))->toBe($fold('0.25', '7', '1.5', '3'));
})->with([
    'sum' => [Aggregation::Sum],
    'count' => [Aggregation::Count],
    'max' => [Aggregation::Max],
]);

it('starts every meter at zero', function (Aggregation $aggregation): void {
    expect((string) $aggregation->fold(Quantity::zero(), Quantity::zero()))
        ->toBe($aggregation === Aggregation::Count ? '1.000000' : '0.000000');
})->with([
    'sum' => [Aggregation::Sum],
    'count' => [Aggregation::Count],
    'max' => [Aggregation::Max],
]);

it('is stored as the word the API and the panel both use', function (): void {
    expect(array_map(
        static fn(Aggregation $aggregation): string => $aggregation->value,
        Aggregation::cases(),
    ))->toBe(['sum', 'count', 'max']);
});

it('says what it does in words a person reading the panel can check', function (): void {
    expect(Aggregation::Sum->label())->toBe('Sum of quantities')
        ->and(Aggregation::Count->label())->toBe('Count of events')
        ->and(Aggregation::Max->label())->toBe('Highest quantity');
});
