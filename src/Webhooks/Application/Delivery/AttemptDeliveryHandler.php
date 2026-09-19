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
 * One attempt at one delivery, in three steps.
 *
 * First, in a transaction: is it still due, and does the endpoint's breaker
 * let it through? If so the delivery is leased — its next attempt moved past
 * the time a request can take — so the dispatcher does not hand it to a
 * second worker meanwhile. Then the request, outside any transaction: a
 * database transaction held open across somebody else's server is a lock
 * held at their mercy. Last, in a transaction again: the attempt is recorded,
 * the delivery moves on, the breaker learns the result.
 *
 * A worker killed between the steps leaves a leased delivery, which falls due
 * again when the lease ends. The receiver may then see the event twice, which
 * at-least-once delivery promises it might; the event id is there to
 * deduplicate on.
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
     * By endpoint, because "which receiver is failing" is the question a
     * dashboard of deliveries is opened to answer. Endpoints are a tenant's
     * own few, not something that grows with traffic.
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

        // A refused destination was never called: there is no answer to time.
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

        // A disabled endpoint keeps its deliveries: they wait, and go out
        // once it is enabled again.
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

        // Removed with its endpoint while the request was out: nothing is
        // left to record against.
        if (! $delivery instanceof Delivery || ! $endpoint instanceof Endpoint) {
            return;
        }

        $attempted = $delivery->attempted($result, $now, $this->jitter->draw());

        $this->deliveries->save($attempted);
        $this->attempts->record($attempted, $attempted->attempts, $sentAt, $result);

        // A receiver that answered at all is up, even when it refused this
        // delivery; the breaker counts only what says it is not.
        $down = $result->refusedDestination || $result->verdict() === Verdict::Retry;

        $this->endpoints->save($endpoint->withBreaker(
            $down ? $endpoint->breaker->failed($now, $this->breakerThreshold) : $endpoint->breaker->succeeded(),
        ));
    }
}
