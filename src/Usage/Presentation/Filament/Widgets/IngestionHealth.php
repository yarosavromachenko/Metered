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
 * Stream depth (accepted but not yet written), recent events, rejections and
 * rows in the default partition.
 */
final class IngestionHealth extends StatsOverviewWidget
{
    private const int COUNT_CAP = 100_000;

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

        // Capped count, since the widget polls. By occurred_at: indexed.
        $recent = DB::query()->fromSub(
            DB::table('usage_events')
                ->select('occurred_at')
                ->where('project_id', $tenant?->projectId->value)
                ->where('occurred_at', '>=', now()->subHour())
                ->limit(self::COUNT_CAP + 1),
            'recent',
        )->count();

        return [
            Stat::make('Waiting in the stream', (string) $depth)
                ->description($depth === 0 ? 'The consumer is caught up' : 'Accepted, not yet written')
                ->color($depth > 100_000 ? 'danger' : ($depth > 0 ? 'warning' : 'success')),

            Stat::make('Events in the last hour', $recent > self::COUNT_CAP
                ? number_format(self::COUNT_CAP) . '+'
                : number_format($recent))
                ->description('By when they happened, in this project'),

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
