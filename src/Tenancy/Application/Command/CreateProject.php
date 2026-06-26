<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Tenancy\Application\Authorization\Actor;
use Metered\Tenancy\Domain\Environment;

final readonly class CreateProject
{
    public function __construct(
        public Uuid $organizationId,
        public string $name,
        public Environment $environment,
        public string $currency,
        public Actor $actor,
    ) {}
}
