<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use RuntimeException;

/**
 * The API answered with a failure the simulation cannot work around.
 */
final class ApiRefused extends RuntimeException
{
    public static function answered(string $method, string $path, int $status, string $detail): self
    {
        return new self(sprintf('%s %s answered %d: %s', $method, $path, $status, $detail));
    }
}
