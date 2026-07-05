<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Console;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\ProjectDirectory;
use Metered\Usage\Infrastructure\Persistence\Drift;
use Metered\Usage\Infrastructure\Persistence\UsageReconciler;
use Psr\Clock\ClockInterface;

/**
 * `usage:reconcile` — proves that every aggregate equals the events under it.
 *
 * Exits non-zero when it finds drift, so a chaos scenario or a CI job can end
 * on it. `--repair` rewrites the window from the raw events, and is not
 * automatic: drift means something upstream is wrong, and an aggregate
 * silently repaired every night is a bug nobody ever finds.
 */
final class ReconcileUsageCommand extends Command
{
    protected $signature = 'usage:reconcile
        {--project= : The project id to check; every project by default}
        {--from= : Start of the window, ISO 8601; 24 hours ago by default}
        {--to= : End of the window, ISO 8601; now by default}
        {--repair : Rewrite the aggregates in the window from the events under them}';

    protected $description = 'Compare usage aggregates against the raw events they were folded from';

    public function handle(
        UsageReconciler $reconciler,
        ProjectDirectory $projects,
        ClockInterface $clock,
    ): int {
        $now = $clock->now();
        $from = $this->instant('from') ?? $now->sub(new DateInterval('P1D'));
        $to = $this->instant('to') ?? $now;

        $tenants = $this->tenants($projects);

        if ($tenants === []) {
            $this->warn('No project to check.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($tenants as $tenant) {
            $drifts = $reconciler->check($tenant, $from, $to);
            $total += count($drifts);

            $this->reportOn($tenant, $drifts);

            if ($drifts !== [] && $this->option('repair') === true) {
                $rows = $reconciler->repair($tenant, $from, $to);
                $this->info(sprintf('  repaired: %d aggregate(s) rewritten from the events.', $rows));
            }
        }

        if ($total === 0) {
            $this->info(sprintf(
                'No drift between %s and %s: every aggregate equals the events under it.',
                $from->format(DATE_ATOM),
                $to->format(DATE_ATOM),
            ));

            return self::SUCCESS;
        }

        // Non-zero, because this is what a chaos run and a CI job assert on.
        return $this->option('repair') === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<Drift>  $drifts
     */
    private function reportOn(TenantContext $tenant, array $drifts): void
    {
        if ($drifts === []) {
            return;
        }

        $this->error(sprintf('Project %s: %d aggregate(s) disagree with their events.', $tenant->projectId->value, count($drifts)));

        foreach (array_slice($drifts, 0, 20) as $drift) {
            $this->line(sprintf(
                '  [%s] customer %s meter %s at %s — %s',
                $drift->kind,
                $drift->customerId,
                $drift->meterId,
                $drift->bucketStart,
                $drift->describe(),
            ));
        }

        if (count($drifts) > 20) {
            $this->line(sprintf('  … and %d more.', count($drifts) - 20));
        }
    }

    /**
     * @return list<TenantContext>
     */
    private function tenants(ProjectDirectory $projects): array
    {
        $given = $this->option('project');

        if (! is_string($given) || $given === '') {
            return $projects->all();
        }

        $tenant = $projects->find(Uuid::fromString($given));

        return $tenant instanceof TenantContext ? [$tenant] : [];
    }

    private function instant(string $option): ?DateTimeImmutable
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        return new DateTimeImmutable($value, $utc)->setTimezone($utc);
    }
}
