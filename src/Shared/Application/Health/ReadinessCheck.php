<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

/**
 * One thing the application needs before it should be sent traffic.
 *
 * Modules contribute their own checks under {@see self::TAG}; the readiness
 * endpoint runs whatever is tagged, without the kernel knowing which module
 * needs what. A check may throw — a dependency that cannot be reached often
 * says so with an exception — and {@see Readiness} turns that into a failure.
 */
interface ReadinessCheck
{
    public const string TAG = 'metered.readiness_checks';

    /**
     * The key the check is reported under. Stable: a probe or a dashboard may
     * match on it.
     */
    public function name(): string;

    public function check(): CheckResult;
}
