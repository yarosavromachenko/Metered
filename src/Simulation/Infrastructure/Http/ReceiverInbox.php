<?php

declare(strict_types=1);

namespace Metered\Simulation\Infrastructure\Http;

use Illuminate\Http\Client\Factory;
use Metered\Simulation\Application\Port\WebhookInbox;
use RuntimeException;

/**
 * docker/webhook-receiver: one path per mode; POST /_secrets registers a
 * signing secret.
 */
final readonly class ReceiverInbox implements WebhookInbox
{
    public function __construct(
        private Factory $http,
        private string $baseUrl,
    ) {}

    public function url(string $mode): string
    {
        return rtrim($this->baseUrl, '/') . '/' . $mode;
    }

    public function trust(string $secret): void
    {
        $response = $this->http->createPendingRequest()->asJson()->withoutRedirecting()->post(rtrim($this->baseUrl, '/') . '/_secrets', ['secret' => $secret]);

        if ($response->status() !== 303) {
            throw new RuntimeException(sprintf('The webhook receiver at %s did not take the secret (answered %d).', $this->baseUrl, $response->status()));
        }
    }
}
