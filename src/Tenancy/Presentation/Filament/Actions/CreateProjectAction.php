<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Metered\Shared\Domain\Exception\DomainException;
use Metered\Tenancy\Application\Authorization\PermissionDenied;
use Metered\Tenancy\Application\Command\CreateProject;
use Metered\Tenancy\Application\Command\CreateProjectHandler;
use Metered\Tenancy\Application\Command\ProjectSlugTaken;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * Opening another project, through the handler rather than through a form
 * save (ADR-0015). The rules about slugs, currencies and who may do this live
 * behind that call, where the API will meet the same ones.
 */
final class CreateProjectAction
{
    public static function make(): Action
    {
        return Action::make('create')
            ->label('New project')
            ->icon('heroicon-o-plus')
            ->visible(static fn(): bool => app(PanelScope::class)->may(Permission::ManageTenant))
            ->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('environment')
                    ->options(['test' => 'Test', 'live' => 'Live'])
                    ->default('test')
                    ->required(),
                TextInput::make('currency')
                    ->default('EUR')
                    ->required()
                    ->maxLength(3)
                    ->helperText('Everything priced in this project uses this currency. It cannot be changed later.'),
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
            $project = app(CreateProjectHandler::class)->handle(new CreateProject(
                organizationId: $tenant->organizationId,
                name: self::text($data['name'] ?? null, ''),
                environment: Environment::tryFrom(self::text($data['environment'] ?? null, 'test')) ?? Environment::Test,
                currency: self::text($data['currency'] ?? null, 'EUR'),
                actor: PanelActor::current(),
            ));
        } catch (ProjectSlugTaken|PermissionDenied|DomainException $refused) {
            Notification::make()->title($refused->getMessage())->danger()->send();

            return null;
        }

        Notification::make()
            ->title(sprintf('Project "%s" created.', $project->name))
            ->success()
            ->send();

        return null;
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
