<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

use Closure;
use SensitiveParameter;

/**
 * With a token, fills that key's tenant; without, creates an organization
 * first (`demo` makes it a demo organization, as for the showcase).
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
