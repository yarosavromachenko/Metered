<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * Carries the plaintext secret; show it once.
 */
final readonly class RegisteredEndpoint
{
    public function __construct(
        public Endpoint $endpoint,
        public SecretKey $secret,
    ) {}
}
