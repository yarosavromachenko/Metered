<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Application\Command\ReconfigureEndpoint;
use Metered\Webhooks\Application\Command\ReconfigureEndpointHandler;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookEndpoint;

final class EditEndpointAction
{
    public static function make(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::OperateWebhooks))
            ->fillForm(static fn(WebhookEndpoint $record): array => [
                'url' => $record->url,
                'description' => $record->description,
                'events' => $record->event_types,
                'enabled' => $record->enabled,
            ])
            ->schema(EndpointForm::fields(withEnabled: true))
            ->action(static fn(WebhookEndpoint $record, array $data): null => self::run($record->id, $data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(string $endpointId, array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($endpointId, $data): string {
            $endpoint = app(ReconfigureEndpointHandler::class)->handle(new ReconfigureEndpoint(
                $tenant,
                Uuid::fromString($endpointId),
                EndpointForm::string($data, 'url'),
                EndpointForm::string($data, 'description'),
                EndpointForm::events($data),
                ($data['enabled'] ?? false) === true,
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Endpoint %s saved.', $endpoint->url);
        });
    }
}
