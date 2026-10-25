<?php

declare(strict_types=1);

namespace Metered\Invoicing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Metered\Invoicing\Application\Metrics\InvoicingMetrics;
use Metered\Invoicing\Domain\Invoice\InvoiceStatus;
use Metered\Shared\Application\Metrics\GaugeReading;
use Metered\Shared\Application\Metrics\GaugeSource;
use Psr\Clock\ClockInterface;

/**
 * Drafts built longer ago than the grace window, all tenants. The close
 * finalizes what it builds, so such a draft is one whose finalization failed.
 */
final readonly class DraftGauges implements GaugeSource
{
    public function __construct(
        private DatabaseManager $db,
        private ClockInterface $clock,
        private int $graceSeconds,
    ) {}

    public function read(): array
    {
        // App clock, not now(): invoices are stamped with it, and the demo moves it.
        $overdue = $this->db->connection()->table('invoices')
            ->where('status', InvoiceStatus::Draft->value)
            ->where('built_at', '<', $this->clock->now()->modify(sprintf('-%d seconds', $this->graceSeconds))->format(DatabaseInvoiceRepository::INSTANT))
            ->count();

        return [new GaugeReading(InvoicingMetrics::draftsOverdue(), $overdue)];
    }
}
