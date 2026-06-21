<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Domain\Scope;

final readonly class IssueApiKey
{
    /**
     * @param  list<Scope>  $scopes
     */
    public function __construct(
        public TenantContext $tenant,
        public string $name,
        public array $scopes,
        public string $actor,
    ) {}
}
