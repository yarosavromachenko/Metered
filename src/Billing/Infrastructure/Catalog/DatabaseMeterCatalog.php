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
 * Catches {@see InvalidMeterCode} and returns null, so a bad code rejects one
 * event, not the batch. Not cached: a newly defined meter must resolve on the
 * next batch.
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

    public function codes(TenantContext $tenant): array
    {
        $codes = array_map(
            static fn(Meter $meter): string => $meter->code->value,
            $this->meters->listFor($tenant),
        );

        sort($codes);

        return $codes;
    }
}
