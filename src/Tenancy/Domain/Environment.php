<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

/**
 * Part of every API key token (`mk_live_…`, `mk_test_…`).
 */
enum Environment: string
{
    case Live = 'live';
    case Test = 'test';

    public function isLive(): bool
    {
        return $this === self::Live;
    }
}
