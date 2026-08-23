<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Command;

use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * An endpoint just registered or rotated, with the secret in the clear — the
 * one moment it is shown.
 */
final readonly class RegisteredEndpoint
{
    public function __construct(
        public Endpoint $endpoint,
        public SecretKey $secret,
    ) {}
}
