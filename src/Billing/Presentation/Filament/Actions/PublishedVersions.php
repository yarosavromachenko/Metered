<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Metered\Billing\Domain\Plan\PlanRepository;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * The published versions of the project in the panel scope, as select
 * options: "pro v2 (month)", plans in code order, newest version first.
 */
final class PublishedVersions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $tenant = app(PanelScope::class)->tenant();

        if (! $tenant instanceof TenantContext) {
            return [];
        }

        $options = [];

        foreach (app(PlanRepository::class)->listFor($tenant) as $plan) {
            foreach (app(PlanVersionRepository::class)->listForPlan($tenant, $plan->id) as $version) {
                if ($version->isPublished()) {
                    $options[$version->id->value] = sprintf('%s v%d (%s)', $plan->code->value, $version->number, $version->interval->value);
                }
            }
        }

        return $options;
    }
}
