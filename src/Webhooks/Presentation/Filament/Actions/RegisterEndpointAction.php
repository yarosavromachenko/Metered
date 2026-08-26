<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;
use Metered\Webhooks\Application\Command\RegisterEndpoint;
use Metered\Webhooks\Application\Command\RegisterEndpointHandler;

final class RegisterEndpointAction
{
    public static function make(): Action
    {
        return Action::make('register')
            ->label('Register endpoint')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::OperateWebhooks))
            ->schema(EndpointForm::fields(withEnabled: false))
            ->action(static fn(array $data): null => self::run($data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($data): string {
            $registered = app(RegisterEndpointHandler::class)->handle(new RegisterEndpoint(
                $tenant,
                EndpointForm::string($data, 'url'),
                EndpointForm::string($data, 'description'),
                EndpointForm::events($data),
                app(PanelScope::class)->actor(),
            ));

            SecretNotice::show($registered->secret);

            return sprintf('Endpoint %s registered.', $registered->endpoint->url);
        });
    }
}
