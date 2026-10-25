<?php

declare(strict_types=1);

use Metered\Webhooks\Domain\Delivery\AttemptResult;

it('keeps a receiver answer that is not valid UTF-8 as valid UTF-8', function (): void {
    $result = AttemptResult::responded(200, 12, "caf\xE9 ok");

    expect(mb_check_encoding($result->responseExcerpt, 'UTF-8'))->toBeTrue()
        ->and($result->responseExcerpt)->toBe('caf? ok');
});

it('keeps an excerpt within the limit after cleaning it', function (): void {
    $result = AttemptResult::responded(200, 12, str_repeat("\xE9", 2000));

    expect(strlen($result->responseExcerpt))->toBe(AttemptResult::EXCERPT_LIMIT)
        ->and(mb_check_encoding($result->responseExcerpt, 'UTF-8'))->toBeTrue();
});

it('does not cut a multibyte character in half', function (): void {
    $result = AttemptResult::responded(200, 12, 'a' . str_repeat('é', 600));

    expect(strlen($result->responseExcerpt))->toBe(AttemptResult::EXCERPT_LIMIT - 1)
        ->and(mb_check_encoding($result->responseExcerpt, 'UTF-8'))->toBeTrue();
});

it('keeps an error text that is not valid UTF-8 as valid UTF-8', function (AttemptResult $result): void {
    expect(mb_check_encoding((string) $result->error, 'UTF-8'))->toBeTrue()
        ->and($result->error)->toBe('host caf?.example');
})->with([
    'unreachable' => [AttemptResult::unreachable("host caf\xE9.example", 3)],
    'refused' => [AttemptResult::refused("host caf\xE9.example")],
]);

it('says whether the receiver is struggling', function (AttemptResult $result, int $slowMilliseconds, bool $struggling): void {
    expect($result->saysReceiverIsStruggling($slowMilliseconds))->toBe($struggling);
})->with([
    'a fast 2xx' => [AttemptResult::responded(200, 4999, 'ok'), 5000, false],
    'a 2xx at the threshold' => [AttemptResult::responded(200, 5000, 'ok'), 5000, true],
    'a slow 2xx' => [AttemptResult::responded(200, 9000, 'ok'), 5000, true],
    'a slow 2xx with the threshold off' => [AttemptResult::responded(200, 9000, 'ok'), 0, false],
    'a 2xx at the lowest threshold' => [AttemptResult::responded(200, 1, 'ok'), 1, true],
    'a fast 4xx' => [AttemptResult::responded(410, 12, ''), 5000, false],
    'a slow 4xx' => [AttemptResult::responded(410, 7000, ''), 5000, true],
    'a fast 5xx' => [AttemptResult::responded(503, 12, ''), 5000, true],
    'a timeout' => [AttemptResult::unreachable('timed out', 10000), 0, true],
    'a refused destination' => [AttemptResult::refused('private address'), 5000, true],
]);
