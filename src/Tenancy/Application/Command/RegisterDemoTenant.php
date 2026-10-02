<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use SensitiveParameter;

/**
 * Demo sign-up form.
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
