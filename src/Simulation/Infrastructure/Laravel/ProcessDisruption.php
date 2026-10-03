<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Metered\Simulation\Application\Port\Disruption;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Daemons as child processes, Redis paused with CLIENT PAUSE; no Docker socket
 * needed.
 */
final class ProcessDisruption implements Disruption
{
    /** Boot time before traffic starts. */
    private const int BOOT_MICROSECONDS = 2_000_000;

    /** @var array<string, Process> */
    private array $processes = [];

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly Repository $config,
        private readonly string $artisan,
    ) {}

    public function start(string $command, array $arguments = []): string
    {
        $process = new Process(['php', $this->artisan, $command, ...$arguments]);
        $process->setTimeout(null);
        $process->start();
        usleep(self::BOOT_MICROSECONDS);

        if (! $process->isRunning()) {
            throw new RuntimeException(sprintf('%s exited at once: %s', $command, trim($process->getErrorOutput() . $process->getOutput())));
        }

        $handle = 'pid-' . $process->getPid();
        $this->processes[$handle] = $process;

        return $handle;
    }

    public function kill(string $handle): void
    {
        $process = $this->processes[$handle] ?? throw new RuntimeException('No daemon ' . $handle . ' was started here.');
        $process->signal(SIGKILL);
        $process->wait();
        unset($this->processes[$handle]);
    }

    public function pendingOf(string $consumer): int
    {
        $pending = $this->redis->connection('usage')->command('xpending', [
            $this->string('metered.usage.stream.key'),
            $this->string('metered.usage.stream.group'),
            '-',
            '+',
            10_000,
            $consumer,
        ]);

        return is_array($pending) ? count($pending) : 0;
    }

    public function stallRedis(int $milliseconds): void
    {
        $this->redis->connection('usage')->command('rawCommand', ['CLIENT', 'PAUSE', (string) $milliseconds, 'ALL']);
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? $value : throw new RuntimeException(sprintf('%s is not configured.', $key));
    }
}
