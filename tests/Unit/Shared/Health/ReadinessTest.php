<?php

declare(strict_types=1);

use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\Readiness;
use Metered\Shared\Application\Health\ReadinessCheck;
use Psr\Log\AbstractLogger;

final readonly class ScriptedReadinessCheck implements ReadinessCheck
{
    public function __construct(
        private string $name,
        private CheckResult|RuntimeException $outcome,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): CheckResult
    {
        return $this->outcome instanceof RuntimeException ? throw $this->outcome : $this->outcome;
    }
}

final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

it('reports every check under its own name', function (): void {
    $readiness = new Readiness([
        new ScriptedReadinessCheck('database', CheckResult::pass()),
        new ScriptedReadinessCheck('usage_backlog', CheckResult::pass('3 pending of 10')),
    ], new CollectingLogger());

    $results = $readiness->evaluate();

    expect(array_keys($results))->toBe(['database', 'usage_backlog'])
        ->and($results['usage_backlog']->detail)->toBe('3 pending of 10')
        ->and(Readiness::isReady($results))->toBeTrue();
});

it('is not ready when any single check fails', function (): void {
    $results = new Readiness([
        new ScriptedReadinessCheck('database', CheckResult::pass()),
        new ScriptedReadinessCheck('usage_backlog', CheckResult::fail('10 pending of 10')),
    ], new CollectingLogger())->evaluate();

    expect(Readiness::isReady($results))->toBeFalse();
});

it('turns an exception into a failure and keeps running the checks after it', function (): void {
    $logger = new CollectingLogger();
    $failure = new RuntimeException('SQLSTATE[08006] could not connect to server "postgres" as user "metered"');

    $results = new Readiness([
        new ScriptedReadinessCheck('database', $failure),
        new ScriptedReadinessCheck('redis', CheckResult::pass()),
    ], $logger)->evaluate();

    // The probe is unauthenticated: the message, with its host and user,
    // goes to the log and not to whoever asked.
    expect($results['database']->passed)->toBeFalse()
        ->and($results['database']->detail)->toBe('unreachable')
        ->and($results['redis']->passed)->toBeTrue()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context'])->toBe(['check' => 'database', 'exception' => $failure]);
});

it('is ready with nothing to check', function (): void {
    expect(Readiness::isReady([]))->toBeTrue();
});
