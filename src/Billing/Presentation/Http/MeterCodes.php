<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Metered\Billing\Domain\Meter;

final class MeterCodes
{
    /**
     * @param list<Meter> $meters
     *
     * @return array<string, string>
     */
    public static function of(array $meters): array
    {
        $codes = [];

        foreach ($meters as $meter) {
            $codes[$meter->id->value] = $meter->code->value;
        }

        return $codes;
    }
}
