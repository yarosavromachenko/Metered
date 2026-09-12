<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

/**
 * What one readiness check found.
 *
 * The detail is shown on an unauthenticated endpoint, so it names the
 * condition ("unreachable", a depth against its limit) and never carries an
 * exception message, which can hold a host name or a credential.
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
