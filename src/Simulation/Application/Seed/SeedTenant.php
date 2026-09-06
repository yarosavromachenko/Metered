<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use Closure;
use SensitiveParameter;

/**
 * Fill a tenant with a profile's worth of catalog, customers and usage.
 *
 * With a token, the tenant it belongs to is filled — a demo sign-up's own.
 * Without one, a new organization is created first; `demo` creates it as a
 * demo organization, which is what the showcase is.
 */
final readonly class SeedTenant
{
    /**
     * @param  (Closure(string): void)|null  $progress  told what is being done, for a console to show
     */
    public function __construct(
        public Profile $profile,
        public int $seed = 1,
        public string $organizationName = 'Northwind Cloud',
        #[SensitiveParameter]
        public ?string $token = null,
        public ?Closure $progress = null,
        public bool $demo = false,
    ) {}
}
