<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;

/**
 * Sends one signed delivery to a tenant's URL and reports what came back.
 *
 * The only way anything in this system reaches a URL a tenant chose. An
 * implementation must refuse addresses that are not public, connect to the
 * address it checked rather than resolving again, and follow no redirect
 * (ADR-0011); a refusal is a result, not an exception.
 */
interface WebhookTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult;
}
