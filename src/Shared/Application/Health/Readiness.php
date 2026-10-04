<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs all checks, also after a failure, so the probe shows everything wrong.
 */
final readonly class Readiness
{
    /**
     * @param  list<ReadinessCheck>  $checks
     */
    public function __construct(
        private array $checks,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, CheckResult>
     */
    public function evaluate(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[$check->name()] = $check->check();
            } catch (Throwable $exception) {
                // Exception details go to the log, not to the probe response.
                $this->logger->warning('Readiness check failed.', [
                    'check' => $check->name(),
                    'exception' => $exception,
                ]);

                $results[$check->name()] = CheckResult::fail('unreachable');
            }
        }

        return $results;
    }

    /**
     * @param  array<string, CheckResult>  $results
     */
    public static function isReady(array $results): bool
    {
        return array_all($results, fn(CheckResult $result): bool => $result->passed);
    }
}
