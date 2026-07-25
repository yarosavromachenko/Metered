<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Subscriptions;

use BackedEnum;
use DateTimeImmutable;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Billing\Domain\Subscription\SubscriptionStatus;
use Metered\Billing\Infrastructure\Eloquent\Subscription;
use Metered\Billing\Infrastructure\Eloquent\SubscriptionPhase;
use Metered\Billing\Presentation\Filament\Actions\CancelSubscriptionAction;
use Metered\Billing\Presentation\Filament\Actions\ChangeSubscriptionPlanAction;
use Metered\Billing\Presentation\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use Metered\Tenancy\Application\Contract\PanelScope;
use Psr\Clock\ClockInterface;
use UnitEnum;

final class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static UnitEnum|string|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 5;

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->with(['customer', 'phases.planVersion.plan'])
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.reference')->label('Customer')->fontFamily('mono')->searchable(),
                TextColumn::make('plan')
                    ->label('Plan')
                    ->state(static fn(Subscription $record): array => self::phases($record))
                    ->listWithLineBreaks(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn(SubscriptionStatus $state): string => ucfirst(str_replace('_', ' ', $state->value)))
                    ->color(static fn(SubscriptionStatus $state): string => match ($state) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::PendingCancellation => 'warning',
                        SubscriptionStatus::Canceled => 'gray',
                    }),
                TextColumn::make('anchor_at')->dateTime('Y-m-d H:i')->label('Anchored'),
                TextColumn::make('ends_at')->dateTime('Y-m-d H:i')->label('Ends')->placeholder('—'),
            ])
            ->recordActions([ChangeSubscriptionPlanAction::make(), CancelSubscriptionAction::make()])
            ->defaultSort('anchor_at', 'desc')
            ->emptyStateHeading('No subscriptions yet')
            ->emptyStateDescription('A subscription puts a customer on a published plan version.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/')];
    }

    /**
     * Each phase as "pro v2", the current one marked, a scheduled one dated.
     *
     * @return list<string>
     */
    private static function phases(Subscription $record): array
    {
        $now = app(ClockInterface::class)->now();

        return array_values($record->phases
            ->sortBy('starts_at')
            ->map(static function (SubscriptionPhase $phase) use ($now): string {
                $name = sprintf('%s v%d', $phase->planVersion->plan->code, $phase->planVersion->number);

                return match (true) {
                    $phase->starts_at > $now => sprintf('%s from %s', $name, $phase->starts_at->format('Y-m-d')),
                    $phase->ends_at instanceof DateTimeImmutable && $phase->ends_at <= $now => sprintf('%s (ended)', $name),
                    default => $name,
                };
            })
            ->all());
    }
}
