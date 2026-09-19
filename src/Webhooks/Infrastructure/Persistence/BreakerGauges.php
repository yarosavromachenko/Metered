<?php

declare(strict_types=1);

namespace Metered\Webhooks\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Metered\Webhooks\Application\Metrics\WebhookMetrics;

/**
 * Whether each enabled endpoint's breaker is holding its deliveries back:
 * 1 while open or half-open, 0 while closed.
 *
 * Read across every tenant, because it is the operator who watches it. A
 * disabled endpoint is left out; its owner stopped it, and nothing is failing.
 */
final readonly class BreakerGauges implements GaugeSource
{
    public function __construct(private DatabaseManager $db) {}

    public function read(): array
    {
        $readings = [];

        foreach ($this->db->connection()->table('webhook_endpoints')->where('enabled', true)->get(['id', 'breaker_state']) as $row) {
            $values = get_object_vars($row);
            $open = RowReader::string($values['breaker_state'] ?? null, 'breaker_state') !== 'closed';

            $readings[] = new GaugeReading(
                WebhookMetrics::breakerOpen(),
                $open ? 1 : 0,
                ['endpoint' => RowReader::string($values['id'] ?? null, 'id')],
            );
        }

        return $readings;
    }
}
