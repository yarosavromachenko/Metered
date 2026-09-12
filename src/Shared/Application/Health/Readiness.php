<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Health;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs every readiness check and reports each one.
 *
 * All checks run even after one fails: an operator looking at a failing
 * probe wants to know everything that is wrong, not the first thing in
 * registration order.
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
                // The reason goes to the log, where the operator can read it;
                // the probe's caller learns only that the dependency failed.
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
