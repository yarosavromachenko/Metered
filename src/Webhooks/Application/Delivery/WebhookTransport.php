<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;

/**
 * The only path to tenant-chosen URLs. Implementations refuse non-public
 * addresses, connect to the checked address without resolving again and
 * follow no redirects (ADR-0011). A refusal is returned, not thrown.
 */
interface WebhookTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult;
}
