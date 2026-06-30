<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Catalog;

use Metered\Billing\Application\Contract\MeterCatalog;
use Metered\Billing\Application\Contract\MeterDescriptor;
use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Shared\Domain\Tenant\TenantContext;

/**
 * The published view of Billing's meters, for the modules that may not look
 * inside.
 *
 * It swallows {@see InvalidMeterCode} on purpose, and that is the whole of
 * why it exists. Ingestion asks about whatever string a client put in an
 * event; a code with a space in it is not an exception to unwind a batch of
 * five hundred events, it is one event that names no meter and is rejected
 * with a reason.
 *
 * Deliberately not cached. A meter defined a second ago has to answer on the
 * next batch — a stale negative would reject real usage, and the events that
 * arrive during the TTL are exactly the ones a tenant is watching for while
 * they test their integration. The consumer resolves each distinct code once
 * per batch, which is where the repetition actually is.
 */
final readonly class DatabaseMeterCatalog implements MeterCatalog
{
    public function __construct(private MeterRepository $meters) {}

    public function find(TenantContext $tenant, string $code): ?MeterDescriptor
    {
        try {
            $parsed = MeterCode::fromString($code);
        } catch (InvalidMeterCode) {
            return null;
        }

        $meter = $this->meters->findByCode($tenant, $parsed);

        return $meter instanceof Meter
            ? new MeterDescriptor($meter->id, $meter->code->value, $meter->aggregation)
            : null;
    }
}
