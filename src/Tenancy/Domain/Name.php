<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

use Metered\Tenancy\Domain\Exception\InvalidTenantName;

/**
 * The one rule organizations and projects share about their display names.
 *
 * Not a value object: a name has no behaviour and no identity, and wrapping it
 * would mean unwrapping it at every point where it is rendered. What the two
 * entities do share is the validation, so that is what lives here.
 */
final readonly class Name
{
    private function __construct() {}

    public static function of(string $name, string $subject, int $limit): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw InvalidTenantName::empty($subject);
        }

        if (mb_strlen($trimmed) > $limit) {
            throw InvalidTenantName::tooLong($subject, $limit);
        }

        return $trimmed;
    }
}
