<?php

declare(strict_types=1);

namespace Metered\Shared\Domain\Text;

use Metered\Shared\Domain\Exception\InvalidName;

/**
 * The one rule everything with a display name shares.
 *
 * Not a value object: a name has no behaviour and no identity, and wrapping it
 * would mean unwrapping it at every point where it is rendered. What the
 * entities do share is the validation, so that is what lives here — in the
 * kernel, because organizations, projects, meters and customers are four
 * things in three modules and they all answer to the same rule.
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
