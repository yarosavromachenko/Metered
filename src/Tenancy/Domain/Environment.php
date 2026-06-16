<?php

declare(strict_types=1);

namespace Metered\Tenancy\Domain;

/**
 * Which half of a tenant's world a project belongs to.
 *
 * The environment is part of every API key token (`mk_live_…`, `mk_test_…`),
 * so a key pasted into the wrong configuration file is rejected by its shape
 * rather than by writing test traffic into live billing data.
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
