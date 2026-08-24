<?php

declare(strict_types=1);

namespace Tests\Support;

use Metered\Webhooks\Application\Delivery\WebhookTransport;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;

/**
 * A receiver that answers from a script and remembers every request, for
 * tests of the pipeline around the transport rather than of the network.
 */
final class RecordingTransport implements WebhookTransport
{
    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    public array $sent = [];

    /**
     * @param list<AttemptResult> $results answered in order; the last one repeats
     */
    public function __construct(private array $results) {}

    public function send(EndpointUrl $url, array $headers, string $body): AttemptResult
    {
        $this->sent[] = ['url' => $url->value, 'headers' => $headers, 'body' => $body];

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }
}
