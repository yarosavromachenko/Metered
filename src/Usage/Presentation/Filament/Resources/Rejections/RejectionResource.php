<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Rejections;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Usage\Domain\RejectionReason;
use Metered\Usage\Infrastructure\Eloquent\UsageEventRejection;
use Metered\Usage\Presentation\Filament\Resources\Rejections\Pages\ListRejections;
use UnitEnum;

/**
 * Events that arrived and were not counted.
 *
 * This screen is why ingestion can answer `202` before it knows whether the
 * meter exists (ADR-0003). Validation is asynchronous, so the answer has to
 * be somewhere a tenant can find it — with the reason, the detail and the
 * event exactly as they sent it. Without this screen the same design would
 * just be silent data loss.
 */
final class RejectionResource extends Resource
{
    protected static ?string $model = UsageEventRejection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Rejections';

    protected static UnitEnum|string|null $navigationGroup = 'Usage';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function getNavigationBadge(): ?string
    {
        $tenant = app(PanelScope::class)->tenant();

        $count = DB::table('usage_event_rejections')
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value)
            ->where('rejected_at', '>=', now()->subDay())
            ->count();

        // Only when there is something to say. A badge showing zero teaches
        // people to ignore the badge.
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rejected_at')->dateTime('Y-m-d H:i:s')->label('Rejected')->sortable(),
                TextColumn::make('reason')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(static fn(RejectionReason $state): string => $state->label()),
                TextColumn::make('event_id')->label('Event id')->fontFamily('mono')->searchable(),
                TextColumn::make('detail')->wrap()->label('What went wrong'),
            ])
            ->filters([
                SelectFilter::make('reason')->options(self::reasons()),
            ])
            ->defaultSort('rejected_at', 'desc')
            ->emptyStateHeading('Nothing rejected')
            ->emptyStateDescription('Every event that arrived was counted.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListRejections::route('/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function reasons(): array
    {
        $options = [];

        foreach (RejectionReason::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }
}
