<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

/**
 * Modules register checks under {@see self::TAG}. A check may throw;
 * {@see Readiness} reports that as a failure.
 */
interface ReadinessCheck
{
    public const string TAG = 'metered.readiness_checks';

    /**
     * Stable: probes and dashboards match on it.
     */
    public function name(): string;

    public function check(): CheckResult;
}
