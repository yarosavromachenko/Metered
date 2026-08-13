<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Metered\Billing\Domain\Customer;
use Metered\Billing\Domain\Meter;

/**
 * Hourly aggregates written straight into the table, for tests of what reads
 * them — an invoice above all. The path from events to aggregates has tests
 * of its own.
 */
final class UsageFactory
{
    public static function aggregate(Customer $customer, Meter $meter, string $bucket, string $quantity, int $events = 1): void
    {
        DB::table('usage_aggregates')->insert([
            'organization_id' => $customer->tenant->organizationId->value,
            'project_id' => $customer->tenant->projectId->value,
            'customer_id' => $customer->id->value,
            'meter_id' => $meter->id->value,
            'meter_code' => $meter->code->value,
            'customer_ref' => $customer->reference->value,
            'bucket_start' => $bucket,
            'quantity' => $quantity,
            'event_count' => $events,
            'updated_at' => $bucket,
        ]);
    }
}
