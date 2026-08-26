<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Resources\Deliveries;

use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Domain\Delivery\DeliveryStatus;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookAttempt;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;
use Metered\Webhooks\Presentation\Filament\Actions\ReplayDeliveryAction;
use Metered\Webhooks\Presentation\Filament\Resources\Deliveries\Pages\ListWebhookDeliveries;
use Metered\Webhooks\Presentation\Filament\Resources\Deliveries\Pages\ViewWebhookDelivery;
use UnitEnum;

final class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'Deliveries';

    protected static UnitEnum|string|null $navigationGroup = 'Webhooks';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'webhook-deliveries';

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->with('endpoint')
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Created')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event_type')->label('Event')->badge()->color('gray'),
                TextColumn::make('endpoint.url')->label('Endpoint')->fontFamily('mono')->limit(48),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn(DeliveryStatus $state): string => ucfirst($state->value))
                    ->color(static fn(DeliveryStatus $state): string => self::statusColor($state)),
                TextColumn::make('attempts')->alignEnd(),
                TextColumn::make('last_status_code')->label('Last answer')->placeholder('—'),
                TextColumn::make('next_attempt_at')->label('Next attempt')->dateTime('Y-m-d H:i:s')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(
                    array_map(static fn(DeliveryStatus $s): string => $s->value, DeliveryStatus::cases()),
                    array_map(static fn(DeliveryStatus $s): string => ucfirst($s->value), DeliveryStatus::cases()),
                )),
            ])
            ->recordActions([ReplayDeliveryAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nothing delivered yet')
            ->emptyStateDescription('Deliveries appear when an event reaches an endpoint that listens to it.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('event_type')->label('Event')->badge(),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(static fn(DeliveryStatus $state): string => ucfirst($state->value))
                    ->color(static fn(DeliveryStatus $state): string => self::statusColor($state)),
                TextEntry::make('endpoint.url')->label('Endpoint')->fontFamily('mono')->columnSpan(2),
                TextEntry::make('event_id')->label('Event id')->fontFamily('mono')->columnSpan(2),
                TextEntry::make('attempts'),
                TextEntry::make('next_attempt_at')->label('Next attempt')->dateTime('Y-m-d H:i:s')->placeholder('—'),
            ]),
            Section::make('Body')
                ->description('The bytes every attempt sends and signs.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    TextEntry::make('body')->hiddenLabel()->html()
                        ->state(static fn(WebhookDelivery $record): string => '<pre class="text-xs">' . e(self::pretty($record->body)) . '</pre>'),
                ]),
            Section::make('Attempts')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('attemptLog')->hiddenLabel()
                        ->state(static fn(WebhookDelivery $record): Collection => $record->attemptLog->sortBy('id')->values())
                        ->schema([
                            TextEntry::make('number')->label('#'),
                            TextEntry::make('attempted_at')->label('At')->dateTime('Y-m-d H:i:s'),
                            TextEntry::make('status_code')->label('Answer')->placeholder('none'),
                            TextEntry::make('duration_ms')->label('Took')->suffix(' ms'),
                            TextEntry::make('error')->placeholder('—')->columnSpan(2),
                            TextEntry::make('response_excerpt')->label('Receiver said')->placeholder('—')->columnSpanFull()->color('gray')
                                ->state(static fn(WebhookAttempt $record): string => $record->response_excerpt),
                        ])->columns(6),
                ]),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
            'view' => ViewWebhookDelivery::route('/{record}'),
        ];
    }

    private static function statusColor(DeliveryStatus $status): string
    {
        return match ($status) {
            DeliveryStatus::Pending => 'warning',
            DeliveryStatus::Succeeded => 'success',
            DeliveryStatus::Failed, DeliveryStatus::Dead => 'danger',
        };
    }

    private static function pretty(string $body): string
    {
        $decoded = json_decode($body, true);

        return $decoded === null ? $body : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
