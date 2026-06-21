<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeySecret;
use Metered\Tenancy\Domain\Organization;
use Metered\Tenancy\Domain\Project;

/**
 * What provisioning produced, including the one and only moment the secret
 * exists. Whoever receives this shows it once and then lets it go.
 */
final readonly class ProvisionedTenant
{
    public function __construct(
        public Organization $organization,
        public Project $project,
        public ApiKey $apiKey,
        public ApiKeySecret $secret,
    ) {}
}
