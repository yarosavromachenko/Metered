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
