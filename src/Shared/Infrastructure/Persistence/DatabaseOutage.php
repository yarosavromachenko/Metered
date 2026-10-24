<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Persistence;

use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Tells a database that could not be reached from one that refused the work.
 * SQLSTATE class 08 (connection), 53 (insufficient resources) and 57P01–57P03
 * (shutdown, crash, not yet accepting connections); PgBouncer's own errors are
 * recognised by their message.
 */
final readonly class DatabaseOutage
{
    private const array CLASSES = ['08', '53'];

    private const array STATES = ['57P01', '57P02', '57P03'];

    public function __construct(private LostConnectionDetector $lostConnection) {}

    public function causedBy(Throwable $failure): bool
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($this->lostConnection->causedByLostConnection($cause)) {
                return true;
            }

            if (! $cause instanceof QueryException && ! $cause instanceof PDOException) {
                continue;
            }

            $state = (string) $cause->getCode();

            if (in_array(substr($state, 0, 2), self::CLASSES, true) || in_array($state, self::STATES, true)) {
                return true;
            }
        }

        return false;
    }
}
