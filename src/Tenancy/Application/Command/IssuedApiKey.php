<?php

declare(strict_types=1);

namespace Metered\Tenancy\Application\Command;

use Metered\Tenancy\Domain\ApiKey;
use Metered\Tenancy\Domain\ApiKeySecret;

final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        public ApiKeySecret $secret,
    ) {}
}
