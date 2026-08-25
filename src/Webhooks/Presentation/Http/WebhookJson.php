<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Http;

use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Metered\Webhooks\Domain\Signing\SecretKey;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;

/**
 * Endpoints and deliveries as the management API returns them. A secret
 * appears in the clear only in the answer that created it.
 */
final class WebhookJson
{
    /**
     * @return array<string, mixed>
     */
    public static function endpoint(Endpoint $endpoint, ?SecretKey $newSecret = null): array
    {
        $json = [
            'id' => $endpoint->id->value,
            'url' => $endpoint->url->value,
            'description' => $endpoint->description,
            'events' => array_map(static fn(EventType $t): string => $t->value, $endpoint->eventTypes),
            'enabled' => $endpoint->enabled,
            'secret' => $endpoint->secret->masked(),
            'breaker' => [
                'state' => $endpoint->breaker->state->value,
                'consecutive_failures' => $endpoint->breaker->consecutiveFailures,
            ],
            'created_at' => $endpoint->createdAt->format(DATE_ATOM),
        ];

        if ($newSecret instanceof SecretKey) {
            $json['signing_secret'] = $newSecret->reveal();
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public static function endpointRow(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'events' => $endpoint->event_types,
            'enabled' => $endpoint->enabled,
            'breaker' => [
                'state' => $endpoint->breaker_state->value,
                'consecutive_failures' => $endpoint->consecutive_failures,
            ],
            'created_at' => $endpoint->created_at->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delivery(Delivery $delivery): array
    {
        return [
            'id' => $delivery->id->value,
            'endpoint_id' => $delivery->endpointId->value,
            'event_id' => $delivery->eventId->value,
            'event_type' => $delivery->eventType->value,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->nextAttemptAt?->format(DATE_ATOM),
            'last_status_code' => $delivery->lastStatusCode,
            'created_at' => $delivery->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deliveryRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'endpoint_id' => $delivery->endpoint_id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->next_attempt_at?->format(DATE_ATOM),
            'last_status_code' => $delivery->last_status_code,
            'last_attempt_at' => $delivery->last_attempt_at?->format(DATE_ATOM),
            'created_at' => $delivery->created_at->format(DATE_ATOM),
        ];
    }
}
