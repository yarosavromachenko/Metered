<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Metered\Billing\Application\Command\CreatePlan;
use Metered\Billing\Application\Command\CreatePlanHandler;
use Metered\Shared\Domain\Access\Permission;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Tenancy\Application\Contract\PanelScope;

final class CreatePlanAction
{
    public static function make(): Action
    {
        return Action::make('createPlan')
            ->label('New plan')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageCatalog))
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(64)
                    ->helperText('What integrations call this plan, such as "pro". Fixed once created.'),
                TextInput::make('name')->required()->maxLength(120),
            ])
            ->action(static fn(array $data): null => self::run($data));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function run(array $data): null
    {
        return Attempt::change(static function (TenantContext $tenant) use ($data): string {
            $plan = app(CreatePlanHandler::class)->handle(new CreatePlan(
                $tenant,
                Form::text($data['code'] ?? null),
                Form::text($data['name'] ?? null),
                app(PanelScope::class)->actor(),
            ));

            return sprintf('Plan "%s" created. Draft its first version next.', $plan->code->value);
        });
    }
}
