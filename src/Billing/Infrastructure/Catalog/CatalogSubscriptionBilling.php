<?php

declare(strict_types=1);

namespace Metered\Billing\Infrastructure\Catalog;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Metered\Billing\Application\Contract\BillablePeriod;
use Metered\Billing\Application\Contract\BillableSubscription;
use Metered\Billing\Application\Contract\Charge;
use Metered\Billing\Application\Contract\SubscriptionBilling;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Period\BillingPeriod;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Billing\Domain\Plan\Price;
use Metered\Billing\Domain\Subscription\Subscription;
use Metered\Billing\Domain\Subscription\SubscriptionRepository;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Quantity\Quantity;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;
use stdClass;

final readonly class CatalogSubscriptionBilling implements SubscriptionBilling
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PlanVersionRepository $versions,
        private MeterRepository $meters,
        private DatabaseManager $db,
    ) {}

    public function billable(DateTimeImmutable $endedAfter): array
    {
        // Across tenants, on purpose: this is the scheduler asking what exists,
        // not a tenant asking what is theirs. Each answer carries its tenant,
        // and everything after this call is scoped by it.
        $rows = $this->db->connection()->table('subscriptions')
            ->where(static function (Builder $query) use ($endedAfter): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $endedAfter->format('Y-m-d H:i:s.uP'));
            })
            ->orderBy('id')
            ->get(['id', 'organization_id', 'project_id', 'customer_id', 'currency', 'anchor_at']);

        $billable = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $values = get_object_vars($row);
                $billable[] = new BillableSubscription(
                    Uuid::fromString(RowReader::string($values['id'] ?? null, 'id')),
                    new TenantContext(
                        Uuid::fromString(RowReader::string($values['organization_id'] ?? null, 'organization_id')),
                        Uuid::fromString(RowReader::string($values['project_id'] ?? null, 'project_id')),
                    ),
                    Uuid::fromString(RowReader::string($values['customer_id'] ?? null, 'customer_id')),
                    RowReader::string($values['currency'] ?? null, 'currency'),
                    RowReader::instant($values['anchor_at'] ?? null, 'anchor_at'),
                );
            }
        }

        return $billable;
    }

    public function find(TenantContext $tenant, Uuid $subscriptionId): ?BillableSubscription
    {
        $subscription = $this->subscriptions->find($tenant, $subscriptionId);

        return $subscription instanceof Subscription
            ? new BillableSubscription($subscription->id, $subscription->tenant, $subscription->customerId, $subscription->currency, $subscription->anchorAt)
            : null;
    }

    public function periodsEndedBy(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $from, DateTimeImmutable $endedBy): array
    {
        $subscription = $this->subscriptions->find($tenant, $subscriptionId);

        if (! $subscription instanceof Subscription) {
            return [];
        }

        return array_map(
            static fn(BillingPeriod $period): BillablePeriod => new BillablePeriod($period->start, $period->end),
            $subscription->periodsEndedBy($from, $endedBy),
        );
    }

    public function charges(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $periodStart, array $usage): array
    {
        $version = $this->versionAt($tenant, $subscriptionId, $periodStart);

        return array_map(function (Price $price) use ($tenant, $usage): Charge {
            if (! $price->meterId instanceof Uuid) {
                $nothing = Quantity::zero();

                return new Charge($price->id, null, null, null, $price->charge($nothing), $price->model->calculation($nothing));
            }

            $quantity = $usage[$price->meterId->value] ?? Quantity::zero();

            return new Charge(
                $price->id,
                $price->meterId,
                $this->meterCode($tenant, $price->meterId),
                $quantity,
                $price->charge($quantity),
                $price->model->calculation($quantity),
            );
        }, $version->prices);
    }

    public function lapse(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $now): void
    {
        $subscription = $this->subscriptions->find($tenant, $subscriptionId);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $lapsed = $subscription->lapse($now);

        if ($lapsed->status !== $subscription->status) {
            $this->subscriptions->save($lapsed);
        }
    }

    private function versionAt(TenantContext $tenant, Uuid $subscriptionId, DateTimeImmutable $at): PlanVersion
    {
        $versionId = $this->subscriptions->find($tenant, $subscriptionId)?->versionAt($at);
        $version = $versionId instanceof Uuid ? $this->versions->find($tenant, $versionId) : null;

        // Invoicing only asks about periods this contract listed, and every
        // one of those lies inside a phase. Anything else is a broken caller.
        if (! $version instanceof PlanVersion) {
            throw new RuntimeException(sprintf('Subscription %s has no plan version at %s.', $subscriptionId, $at->format(DATE_ATOM)));
        }

        return $version;
    }

    private function meterCode(TenantContext $tenant, Uuid $meterId): string
    {
        $meter = $this->meters->find($tenant, $meterId);

        if (! $meter instanceof Meter) {
            throw new RuntimeException(sprintf('Price points at meter %s, which does not exist.', $meterId));
        }

        return $meter->code->value;
    }
}
