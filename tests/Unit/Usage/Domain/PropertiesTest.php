<?php

declare(strict_types=1);

use Metered\Usage\Domain\Exception\InvalidProperties;
use Metered\Usage\Domain\Properties;

it('keeps flat scalar labels as given', function (): void {
    $given = ['region' => 'eu-central', 'tier' => 2, 'beta' => true, 'note' => null, 'city' => 'Kyïv'];

    expect(Properties::fromArray($given)->all())->toBe($given);
});

it('refuses a control character in a name or a value', function (array $given): void {
    expect(static fn(): Properties => Properties::fromArray($given))
        ->toThrow(InvalidProperties::class, 'control character');
})->with([
    'NUL in a value' => [['region' => "eu\u{0}central"]],
    'NUL in a name' => [["reg\u{0}ion" => 'eu-central']],
    'a newline in a value' => [['region' => "eu\ncentral"]],
    'DEL in a value' => [['region' => "eu\x7Fcentral"]],
]);

it('shows a control character in the name escaped', function (): void {
    expect(static fn(): Properties => Properties::fromArray(["reg\u{0}ion" => 'x']))
        ->toThrow(InvalidProperties::class, 'The property "reg\000ion" contains a control character');
});

it('refuses what is not a flat set of named labels', function (array $given, string $message): void {
    expect(static fn(): Properties => Properties::fromArray($given))
        ->toThrow(InvalidProperties::class, $message);
})->with([
    'a list' => [['eu-central'], 'has to be named'],
    'a blank name' => [[' ' => 'x'], 'has to be named'],
    'a nested value' => [['tags' => ['a']], 'nested'],
    'a value over 256 bytes' => [['region' => str_repeat('é', 129)], 'at most 256 bytes'],
    'more than 32 properties' => [[...array_fill_keys(array_map(static fn(int $index): string => 'key' . $index, range(1, 33)), 'x')], 'at most 32'],
]);

it('takes a value of exactly 256 bytes and exactly 32 properties', function (): void {
    $properties = Properties::fromArray([
        'region' => str_repeat('a', 256),
        ...array_fill_keys(array_map(static fn(int $index): string => 'key' . $index, range(1, 31)), 'x'),
    ]);

    expect($properties->all())->toHaveCount(32);
});
