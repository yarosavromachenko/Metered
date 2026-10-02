<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use SensitiveParameter;

/**
 * A stranger signing up on the demo instance: one form, one whole tenant.
 */
final readonly class RegisterDemoTenant
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $plainPassword,
        public string $organizationName,
    ) {}
}
