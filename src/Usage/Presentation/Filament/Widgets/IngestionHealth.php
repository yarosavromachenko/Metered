<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Usage\Application\Stream\StreamDepth;
use Metered\Usage\Infrastructure\Persistence\PartitionManager;

/**
 * Whether ingestion is keeping up, in four numbers.
 *
 * Stream depth is the one that matters: it is the difference between what was
 * accepted and what has been written, and a number that keeps climbing is the
 * only early warning this design gives. The others are the failures that are
 * quiet by nature — rejections a tenant has not looked at, and rows in the
 * default partition, which means a day went by without its partition.
 */
final class IngestionHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Ingestion';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $tenant = app(PanelScope::class)->tenant();
        $depth = app(StreamDepth::class)->pending();
        $health = app(PartitionManager::class)->health();

        $rejected = DB::table('usage_event_rejections')
            ->where('project_id', $tenant?->projectId->value)
            ->where('rejected_at', '>=', now()->subDay())
            ->count();

        $written = DB::table('usage_events')
            ->where('project_id', $tenant?->projectId->value)
            ->where('received_at', '>=', now()->subHour())
            ->count();

        return [
            Stat::make('Waiting in the stream', (string) $depth)
                ->description($depth === 0 ? 'The consumer is caught up' : 'Accepted, not yet written')
                ->color($depth > 100_000 ? 'danger' : ($depth > 0 ? 'warning' : 'success')),

            Stat::make('Events written, last hour', (string) $written)
                ->description('In this project'),

            Stat::make('Rejected, last day', (string) $rejected)
                ->description($rejected === 0 ? 'Everything was counted' : 'Look at the rejections screen')
                ->color($rejected === 0 ? 'success' : 'danger'),

            Stat::make('Partitions', (string) $health['partitions'])
                ->description($health['default_rows'] === 0
                    ? 'Every day has its own'
                    : sprintf('%d row(s) in the default partition', $health['default_rows']))
                ->color($health['default_rows'] === 0 ? 'success' : 'warning'),
        ];
    }
}
