<?php

declare(strict_types=1);

namespace Metered\Webhooks\Application\Metrics;

use Metered\Shared\Application\Metrics\Counter;
use Metered\Shared\Application\Metrics\Gauge;
use Metered\Shared\Application\Metrics\Histogram;

/**
 * See docs/observability.md.
 */
final class WebhookMetrics
{
    public static function deliveries(): Counter
    {
        return new Counter('webhook.deliveries', '{attempt}', 'Delivery attempts, by outcome and endpoint');
    }

    public static function deliveryDuration(): Histogram
    {
        return Histogram::duration('webhook.delivery.duration', 'Time a receiver took to answer, by endpoint');
    }

    public static function breakerOpen(): Gauge
    {
        return new Gauge('webhook.breaker.open', '{breaker}', '1 while an endpoint\'s circuit breaker is open or half-open');
    }
}
