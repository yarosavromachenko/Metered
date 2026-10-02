<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Text;

use Metered\Shared\Domain\Exception\InvalidName;

/**
 * Validation for display names of organizations, projects, meters and
 * customers.
 */
final readonly class Name
{
    private function __construct() {}

    public static function of(string $name, string $subject, int $limit): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw InvalidName::empty($subject);
        }

        if (mb_strlen($trimmed) > $limit) {
            throw InvalidName::tooLong($subject, $limit);
        }

        return $trimmed;
    }
}
