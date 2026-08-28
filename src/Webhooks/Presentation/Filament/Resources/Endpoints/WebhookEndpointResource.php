<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Resources\Endpoints;

use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Domain\Endpoint\BreakerState;
use Metered\Webhooks\Domain\Endpoint\EndpointUrl;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;
use Metered\Webhooks\Infrastructure\Http\TrustedDestination;
use Metered\Webhooks\Presentation\Filament\Actions\EditEndpointAction;
use Metered\Webhooks\Presentation\Filament\Actions\RemoveEndpointAction;
use Metered\Webhooks\Presentation\Filament\Actions\RotateSecretAction;
use Metered\Webhooks\Presentation\Filament\Resources\Endpoints\Pages\ListWebhookEndpoints;
use UnitEnum;

final class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Endpoints';

    protected static UnitEnum|string|null $navigationGroup = 'Webhooks';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'webhook-endpoints';

    public static function getEloquentQuery(): Builder
    {
        $tenant = app(PanelScope::class)->tenant();

        return parent::getEloquentQuery()
            ->where('project_id', $tenant?->projectId->value)
            ->where('organization_id', $tenant?->organizationId->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')->label('URL')->fontFamily('mono')->searchable()
                    ->description(static fn(WebhookEndpoint $record): string => self::trusts($record)
                        ? trim($record->description . ' · the demo receiver, let through the SSRF guard by configuration', ' ·')
                        : $record->description),
                TextColumn::make('event_types')->label('Events')->badge()->separator(','),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('breaker_state')
                    ->label('Breaker')
                    ->badge()
                    ->formatStateUsing(static fn(BreakerState $state): string => str_replace('_', '-', $state->value))
                    ->color(static fn(BreakerState $state): string => match ($state) {
                        BreakerState::Closed => 'success',
                        BreakerState::HalfOpen => 'warning',
                        BreakerState::Open => 'danger',
                    })
                    ->description(static fn(WebhookEndpoint $record): string => $record->consecutive_failures > 0
                        ? sprintf('%d failed in a row', $record->consecutive_failures)
                        : ''),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i')->label('Registered'),
            ])
            ->recordActions([EditEndpointAction::make(), RotateSecretAction::make(), RemoveEndpointAction::make()])
            ->defaultSort('created_at')
            ->emptyStateHeading('No endpoints yet')
            ->emptyStateDescription('An endpoint receives signed POSTs when invoices and subscriptions change.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListWebhookEndpoints::route('/')];
    }

    /**
     * Whether this endpoint is the one private destination the guard lets
     * through — said on screen, so the exception is never invisible.
     */
    private static function trusts(WebhookEndpoint $record): bool
    {
        $configured = config('metered.webhooks.trusted_destination');
        $trusted = TrustedDestination::fromConfig(is_string($configured) ? $configured : null, (string) app()->environment());
        if (! $trusted instanceof TrustedDestination) {
            return false;
        }

        $url = EndpointUrl::fromString($record->url, allowHttp: true);

        return $trusted->matches($url->host, $url->port);
    }
}
