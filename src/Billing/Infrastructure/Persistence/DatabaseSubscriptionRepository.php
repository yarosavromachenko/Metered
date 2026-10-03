<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Persistence;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionPhase;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;
use stdClass;

final readonly class DatabaseSubscriptionRepository implements SubscriptionRepository
{
    /**
     * Keeps microseconds, which the query builder's format drops.
     */
    private const string INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private DatabaseManager $db) {}

    public function save(Subscription $subscription): void
    {
        $connection = $this->db->connection();

        $connection->transaction(function () use ($connection, $subscription): void {
            $connection->table('subscriptions')->upsert([
                'id' => $subscription->id->value,
                'organization_id' => $subscription->tenant->organizationId->value,
                'project_id' => $subscription->tenant->projectId->value,
                'customer_id' => $subscription->customerId->value,
                'anchor_at' => $subscription->anchorAt->format(self::INSTANT),
                'currency' => $subscription->currency,
                'interval' => $subscription->interval->value,
                'status' => $subscription->status->value,
                'ends_at' => $subscription->endsAt?->format(self::INSTANT),
                // Only lifecycle columns change.
            ], ['id'], ['status', 'ends_at']);

            // Phases are replaced; an exclusion constraint forbids overlaps.
            $connection->table('subscription_phases')->where('subscription_id', $subscription->id->value)->delete();

            $connection->table('subscription_phases')->insert(array_map(
                static fn(SubscriptionPhase $phase): array => [
                    'subscription_id' => $subscription->id->value,
                    'organization_id' => $subscription->tenant->organizationId->value,
                    'project_id' => $subscription->tenant->projectId->value,
                    'plan_version_id' => $phase->planVersionId->value,
                    'starts_at' => $phase->startsAt->format(self::INSTANT),
                    'ends_at' => $phase->endsAt?->format(self::INSTANT),
                ],
                $subscription->phases,
            ));
        });
    }

    public function find(TenantContext $tenant, Uuid $id): ?Subscription
    {
        $row = $this->scoped($tenant)->where('id', $id->value)->first();

        return $row instanceof stdClass ? $this->toSubscription($row) : null;
    }

    public function listForCustomer(TenantContext $tenant, Uuid $customerId): array
    {
        $subscriptions = [];

        $rows = $this->scoped($tenant)
            ->where('customer_id', $customerId->value)
            ->orderByDesc('anchor_at')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $subscriptions[] = $this->toSubscription($row);
            }
        }

        return $subscriptions;
    }

    private function scoped(TenantContext $tenant): Builder
    {
        return $this->db->connection()->table('subscriptions')
            ->where('project_id', $tenant->projectId->value)
            ->where('organization_id', $tenant->organizationId->value);
    }

    private function toSubscription(stdClass $row): Subscription
    {
        $values = get_object_vars($row);
        $id = RowReader::string($values['id'] ?? null, 'id');

        $phases = [];

        foreach ($this->db->connection()->table('subscription_phases')->where('subscription_id', $id)->orderBy('starts_at')->get() as $phase) {
            if ($phase instanceof stdClass) {
                $columns = get_object_vars($phase);
                $phases[] = new SubscriptionPhase(
                    Uuid::fromString(RowReader::string($columns['plan_version_id'] ?? null, 'plan_version_id')),
                    RowReader::instant($columns['starts_at'] ?? null, 'starts_at'),
                    RowReader::instantOrNull($columns['ends_at'] ?? null, 'ends_at'),
                );
            }
        }

        if ($phases === []) {
            throw new RuntimeException(sprintf('Subscription %s was stored without a single phase.', $id));
        }

        return Subscription::restore(
            Uuid::fromString($id),
            new TenantContext(
                Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
            ),
            Uuid::fromString(RowReader::string($values['customer_id'] ?? null, 'customer_id')),
            RowReader::instant($values['anchor_at'] ?? null, 'anchor_at'),
            RowReader::string($values['currency'] ?? null, 'currency'),
            BillingInterval::from(RowReader::string($values['interval'] ?? null, 'interval')),
            SubscriptionStatus::from(RowReader::string($values['status'] ?? null, 'status')),
            $phases,
            RowReader::instantOrNull($values['ends_at'] ?? null, 'ends_at'),
        );
    }
}
