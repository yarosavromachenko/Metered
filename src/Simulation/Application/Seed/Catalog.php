<?php

declare(strict_types=1);

namespace Metered\Simulation\Application\Seed;

/**
 * The seeded catalog, in the shapes the management API takes.
 *
 * Four meters, one per aggregation that matters, and four plans that between
 * them use every pricing model — so every calculator has real invoice lines
 * to show, and the tier boundaries are crossed by customers of different
 * sizes rather than by a unit test alone.
 */
final class Catalog
{
    /**
     * @return list<array{code: string, name: string, aggregation: string}>
     */
    public static function meters(): array
    {
        return [
            ['code' => 'api.requests', 'name' => 'API requests', 'aggregation' => 'sum'],
            ['code' => 'storage.gb', 'name' => 'Storage (GB, peak)', 'aggregation' => 'max'],
            ['code' => 'messages.sent', 'name' => 'Messages sent', 'aggregation' => 'count'],
            ['code' => 'compute.minutes', 'name' => 'Compute minutes', 'aggregation' => 'sum'],
        ];
    }

    /**
     * @return array<string, array{name: string, meters: list<string>, prices: list<array<string, mixed>>}>
     */
    public static function plans(): array
    {
        return [
            'starter' => [
                'name' => 'Starter',
                'meters' => ['api.requests'],
                'prices' => [
                    ['model' => 'flat_fee', 'amount' => 1900],
                    ['model' => 'per_unit', 'meter' => 'api.requests', 'unit_price' => '0.0004'],
                ],
            ],
            'growth' => [
                'name' => 'Growth',
                'meters' => ['api.requests', 'storage.gb'],
                'prices' => [
                    ['model' => 'flat_fee', 'amount' => 9900],
                    ['model' => 'graduated', 'meter' => 'api.requests', 'tiers' => [
                        ['up_to' => '20000', 'unit_price' => '0'],
                        ['up_to' => '100000', 'unit_price' => '0.0003'],
                        ['up_to' => null, 'unit_price' => '0.0002'],
                    ]],
                    ['model' => 'per_unit', 'meter' => 'storage.gb', 'unit_price' => '0.12'],
                ],
            ],
            'scale' => [
                'name' => 'Scale',
                'meters' => ['api.requests', 'messages.sent', 'compute.minutes'],
                'prices' => [
                    ['model' => 'flat_fee', 'amount' => 49900],
                    ['model' => 'volume', 'meter' => 'messages.sent', 'tiers' => [
                        ['up_to' => '1000', 'unit_price' => '0.01'],
                        ['up_to' => '5000', 'unit_price' => '0.008'],
                        ['up_to' => null, 'unit_price' => '0.005'],
                    ]],
                    ['model' => 'graduated', 'meter' => 'compute.minutes', 'tiers' => [
                        ['up_to' => '500', 'unit_price' => '0'],
                        ['up_to' => null, 'unit_price' => '0.015'],
                    ]],
                ],
            ],
            'payg' => [
                'name' => 'Pay as you go',
                'meters' => ['api.requests', 'compute.minutes'],
                'prices' => [
                    ['model' => 'per_unit', 'meter' => 'api.requests', 'unit_price' => '0.0005'],
                    ['model' => 'per_unit', 'meter' => 'compute.minutes', 'unit_price' => '0.02'],
                ],
            ],
        ];
    }

    /**
     * Every event the platform announces: the seeded endpoints hear it all.
     *
     * @return list<string>
     */
    public static function webhookEvents(): array
    {
        return ['subscription.created', 'subscription.canceled', 'invoice.finalized', 'invoice.paid', 'invoice.voided'];
    }

    /**
     * The demo receiver's modes (docker/webhook-receiver): one endpoint each,
     * so the delivery log shows success, retries, a slow answer, an endpoint
     * gone for good and a breaker that opens.
     *
     * @return array<string, string>
     */
    public static function receiverModes(): array
    {
        return [
            'ok' => 'Accepts everything and verifies every signature',
            'flaky' => 'Fails the first two attempts at each event, then accepts',
            'slow' => 'Answers after twelve seconds, past the delivery timeout',
            'down' => 'Always fails, so its circuit breaker opens',
            'gone' => 'Answers 410 Gone',
        ];
    }
}
