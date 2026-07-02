<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Metered\Billing\Application\Command\CustomerReferenceTaken;
use Metered\Billing\Application\Command\RegisterCustomer;
use Metered\Billing\Application\Command\RegisterCustomerHandler;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Tenancy\Application\Contract\PanelScope;

/**
 * Registering a customer from the panel, through the handler.
 */
final class RegisterCustomerAction
{
    public static function make(): Action
    {
        return Action::make('register')
            ->label('New customer')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                TextInput::make('reference')
                    ->label('Reference')
                    ->required()
                    ->maxLength(128)
                    ->helperText('The id you already know this customer by. Events carry it, and it is fixed.'),
                TextInput::make('name')->required()->maxLength(120),
            ])
            ->action(static fn(array $data): null => self::run($data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function run(array $data): null
    {
        $tenant = app(PanelScope::class)->tenant();

        if ($tenant === null) {
            return null;
        }

        try {
            $customer = app(RegisterCustomerHandler::class)->handle(new RegisterCustomer(
                tenant: $tenant,
                reference: self::text($data['reference'] ?? null),
                name: self::text($data['name'] ?? null),
                actor: app(PanelScope::class)->actor(),
            ));
        } catch (CustomerReferenceTaken|PermissionDenied|DomainException $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return null;
        }

        Notification::make()
            ->title(sprintf('Customer "%s" registered.', $customer->name))
            ->success()
            ->send();

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
