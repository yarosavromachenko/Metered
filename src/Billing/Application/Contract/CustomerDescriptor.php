<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;

final readonly class CustomerDescriptor
{
    public function __construct(
        public Uuid $id,
        public string $reference,
    ) {}
}
