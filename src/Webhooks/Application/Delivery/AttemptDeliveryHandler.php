<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Delivery;

use DateTimeImmutable;
use Metered\Shared\Application\Metrics\Metrics;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Webhooks\Application\Metrics\WebhookMetrics;
use Metered\Webhooks\Domain\Delivery\AttemptLog;
use Metered\Webhooks\Domain\Delivery\AttemptResult;
use Metered\Webhooks\Domain\Delivery\Delivery;
use Metered\Webhooks\Domain\Delivery\DeliveryRepository;
use Metered\Webhooks\Domain\Delivery\Verdict;
use Metered\Webhooks\Domain\Endpoint\CircuitBreaker;
use Metered\Webhooks\Domain\Endpoint\Endpoint;
use Metered\Webhooks\Domain\Endpoint\EndpointRepository;
use Metered\Webhooks\Domain\Signing\Signature;
use Psr\Clock\ClockInterface;

/**
 * 1. Transaction: check it is due and the breaker allows it, then lease it
 *    (next attempt pushed past the request timeout).
 * 2. HTTP request, outside any transaction.
 * 3. Transaction: record the attempt, advance the delivery, update the breaker.
 *
 * A crash between steps lets the lease expire and the delivery is retried
 * (at-least-once; receivers deduplicate on the event id).
 */
final readonly class AttemptDeliveryHandler
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private EndpointRepository $endpoints,
        private AttemptLog $attempts,
        private WebhookTransport $transport,
        private Transactions $transactions,
        private Jitter $jitter,
        private ClockInterface $clock,
        private int $breakerThreshold,
        private int $breakerCooldownSeconds,
        private int $leaseSeconds,
        private Metrics $metrics,
    ) {}

    public function handle(AttemptDelivery $command): ?AttemptResult
    {
        $claimed = $this->transactions->run(fn(): ?array => $this->claim($command));

        if ($claimed === null) {
            return null;
        }

        [$delivery, $endpoint, $sentAt] = $claimed;

        $result = $this->transport->send($endpoint->url, [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Metered-Webhooks/1',
            'X-Metered-Event-Id' => $delivery->eventId->value,
            'X-Metered-Event-Type' => $delivery->eventType->value,
            Signature::HEADER => Signature::header($delivery->body, $sentAt, $endpoint->signingSecrets($sentAt)),
        ], $delivery->body);

        $this->transactions->run(function () use ($command, $delivery, $sentAt, $result): void {
            $this->record($command, $delivery, $sentAt, $result);
        });

        $this->measure($endpoint, $result);

        return $result;
    }

    /**
     * Labelled by endpoint; the number of endpoints is small.
     */
    private function measure(Endpoint $endpoint, AttemptResult $result): void
    {
        $endpointLabel = ['endpoint' => $endpoint->id->value];
        $outcome = $result->refusedDestination ? 'refused' : match ($result->verdict()) {
            Verdict::Delivered => 'delivered',
            Verdict::Retry => 'retry',
            Verdict::GiveUp => 'given_up',
        };

        $this->metrics->add(WebhookMetrics::deliveries(), 1, [...$endpointLabel, 'outcome' => $outcome]);

        // Refused by the guard: nothing was sent, no duration.
        if (! $result->refusedDestination) {
            $this->metrics->record(WebhookMetrics::deliveryDuration(), $result->durationMs * 1_000_000, $endpointLabel);
        }
    }

    /**
     * @return array{Delivery, Endpoint, DateTimeImmutable}|null
     */
    private function claim(AttemptDelivery $command): ?array
    {
        $now = $this->clock->now();
        $delivery = $this->deliveries->findForUpdate($command->tenant, $command->deliveryId);

        if (! $delivery instanceof Delivery || ! $delivery->isDueAt($now)) {
            return null;
        }

        $endpoint = $this->endpoints->findForUpdate($command->tenant, $delivery->endpointId);

        if (! $endpoint instanceof Endpoint) {
            return null;
        }

        // Disabled: deliveries wait until it is enabled again.
        $breaker = $endpoint->enabled ? $endpoint->breaker->admit($now, $this->breakerCooldownSeconds) : null;

        if (!$breaker instanceof CircuitBreaker) {
            $this->deliveries->save($delivery->postponedUntil(
                $endpoint->breaker->reopensAt($this->breakerCooldownSeconds) ?? $now->modify(sprintf('+%d seconds', $this->breakerCooldownSeconds)),
            ));

            return null;
        }

        $this->endpoints->save($endpoint->withBreaker($breaker));
        $this->deliveries->save($delivery->postponedUntil($now->modify(sprintf('+%d seconds', $this->leaseSeconds))));

        return [$delivery, $endpoint, $now];
    }

    private function record(AttemptDelivery $command, Delivery $claimed, DateTimeImmutable $sentAt, AttemptResult $result): void
    {
        $now = $this->clock->now();
        $delivery = $this->deliveries->findForUpdate($command->tenant, $command->deliveryId);
        $endpoint = $this->endpoints->findForUpdate($command->tenant, $claimed->endpointId);

        // The endpoint was removed during the request.
        if (! $delivery instanceof Delivery || ! $endpoint instanceof Endpoint) {
            return;
        }

        $attempted = $delivery->attempted($result, $now, $this->jitter->draw());

        $this->deliveries->save($attempted);
        $this->attempts->record($attempted, $attempted->attempts, $sentAt, $result);

        // Any HTTP answer means the receiver is up; only unreachability trips the breaker.
        $down = $result->refusedDestination || $result->verdict() === Verdict::Retry;

        $this->endpoints->save($endpoint->withBreaker(
            $down ? $endpoint->breaker->failed($now, $this->breakerThreshold) : $endpoint->breaker->succeeded(),
        ));
    }
}
