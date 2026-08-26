<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Metered\Billing\Application\Command\StartSubscription;
use Metered\Billing\Application\Command\StartSubscriptionHandler;
use Metered\Billing\Domain\CustomerRepository;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Filament\Attempt;
use Metered\Tenancy\Application\Contract\PanelScope;

final class StartSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('startSubscription')
            ->label('New subscription')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                Select::make('customer')
                    ->options(static fn(): array => self::customers())
                    ->searchable()
                    ->required(),
                Select::make('version')
                    ->label('Plan version')
                    ->options(static fn(): array => PublishedVersions::options())
                    ->required()
                    ->helperText('Only published versions can be subscribed to. It starts now, anchored at this moment.'),
            ])
            ->action(static fn(array $data): null => self::run($data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(array $data): null
    {
        return Attempt::change(app(PanelScope::class)->tenant(), static function (TenantContext $tenant) use ($data): string {
            $subscription = app(StartSubscriptionHandler::class)->handle(new StartSubscription(
                $tenant,
                Form::id($data['customer'] ?? null),
                Form::id($data['version'] ?? null),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Subscription started, first period ends %s.', $subscription->periodAt($subscription->anchorAt)->end->format('Y-m-d H:i'));
        });
    }

    /**
     * @return array<string, string>
     */
    private static function customers(): array
    {
        $tenant = app(PanelScope::class)->tenant();
        $options = [];

        foreach ($tenant instanceof TenantContext ? app(CustomerRepository::class)->listFor($tenant) : [] as $customer) {
            $options[$customer->id->value] = $customer->reference->value;
        }

        return $options;
    }
}
