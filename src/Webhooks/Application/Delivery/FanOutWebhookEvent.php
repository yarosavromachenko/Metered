<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

use Metered\Shared\Application\Inbox\IntegrationEventHandler;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Outbox\OutboxMessage;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Endpoint\EventType;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Turns an integration event into one delivery per endpoint that listens to
 * it.
 *
 * Runs behind the inbox, so a redelivered event does nothing; and a delivery
 * is unique per endpoint and event, so even a second run would add nothing.
 * Nothing is sent from here: the deliveries are rows, and the dispatcher sends
 * whatever is due — the same path a retry takes.
 */
final readonly class FanOutWebhookEvent implements IntegrationEventHandler
{
    public function __construct(
        private EndpointRepository $endpoints,
        private DeliveryRepository $deliveries,
        private IdentifierGenerator $ids,
        private ClockInterface $clock,
    ) {}

    public function consumerName(): string
    {
        return 'webhooks.fan-out';
    }

    public function subscribesTo(): array
    {
        return EventType::names();
    }

    public function handle(OutboxMessage $message): void
    {
        $tenant = $this->tenantOf($message);
        $type = EventType::from($message->type);
        $body = $this->body($message);
        $now = $this->clock->now();

        foreach ($this->endpoints->listeningTo($tenant, $type) as $endpoint) {
            $this->deliveries->add(Delivery::schedule($this->ids->generate(), $tenant, $endpoint->id, $message->id, $type, $body, $now));
        }
    }

    /**
     * What a receiver gets: the event's id to deduplicate on, its type, when
     * it happened, and its data — without the tenant ids, which name the
     * receiver's own organization back to it.
     */
    private function body(OutboxMessage $message): string
    {
        $data = $message->payload;
        unset($data['organization_id'], $data['project_id']);

        return json_encode([
            'id' => $message->id->value,
            'type' => $message->type,
            'created_at' => $message->occurredAt->format(DATE_ATOM),
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function tenantOf(OutboxMessage $message): TenantContext
    {
        $organization = $message->payload['organization_id'] ?? null;
        $project = $message->payload['project_id'] ?? null;

        if (! is_string($organization) || ! is_string($project)) {
            throw new RuntimeException(sprintf('Event %s (%s) names no tenant to deliver it to.', $message->id, $message->type));
        }

        return new TenantContext(Uuid::fromString($organization), Uuid::fromString($project));
    }
}
