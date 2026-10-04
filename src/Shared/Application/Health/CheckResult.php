<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

/**
 * The detail is public (unauthenticated endpoint): a condition such as
 * "unreachable", never an exception message.
 */
final readonly class CheckResult
{
    private function __construct(
        public bool $passed,
        public string $detail,
    ) {}

    public static function pass(string $detail = 'ok'): self
    {
        return new self(true, $detail);
    }

    public static function fail(string $detail): self
    {
        return new self(false, $detail);
    }
}
