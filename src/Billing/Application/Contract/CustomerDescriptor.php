<?php

declare(strict_types=1);

namespace Metered\Billing\Application\Contract;

use Metered\Shared\Domain\Identifier\Uuid;

/**
 * What another module is told about a customer: our id and the reference the
 * tenant knows them by.
 */
final readonly class CustomerDescriptor
{
    public function __construct(
        public Uuid $id,
        public string $reference,
    ) {}
}
