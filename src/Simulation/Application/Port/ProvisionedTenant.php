<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Port;

use SensitiveParameter;

final readonly class ProvisionedTenant
{
    public function __construct(
        public string $organizationId,
        public string $organizationSlug,
        public string $projectId,
        #[SensitiveParameter]
        public string $token,
    ) {}
}
