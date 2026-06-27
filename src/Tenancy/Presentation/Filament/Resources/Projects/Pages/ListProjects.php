<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\Projects\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Metered\Tenancy\Application\Command\CreateProject;
use Metered\Tenancy\Application\Command\CreateProjectHandler;
use Metered\Tenancy\Application\Command\ProjectSlugTaken;
use Metered\Tenancy\Domain\Environment;
use Metered\Tenancy\Domain\Permission;
use Metered\Tenancy\Presentation\Filament\PanelActor;
use Metered\Tenancy\Presentation\Filament\PanelScope;
use Metered\Tenancy\Presentation\Filament\Resources\Projects\ProjectResource;

/**
 * Creating a project is a handler call, not a form save (ADR-0015). The action
 * gathers three fields and hands them over; the rules about slugs, currencies
 * and who may do this live behind that call, where the API meets the same ones.
 */
final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('New project')
                ->icon('heroicon-o-plus')
                ->visible(fn(): bool => app(PanelScope::class)->may(Permission::ManageTenant))
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
                ->action(function (array $data): void {
                    $scope = app(PanelScope::class);
                    $tenant = $scope->tenant();

                    if ($tenant === null) {
                        return;
                    }

                    try {
                        $project = app(CreateProjectHandler::class)->handle(new CreateProject(
                            organizationId: $tenant->organizationId,
                            name: $this->text($data['name'] ?? null),
                            environment: Environment::from($this->text($data['environment'] ?? null, 'test')),
                            currency: $this->text($data['currency'] ?? null, 'EUR'),
                            actor: PanelActor::current(),
                        ));
                    } catch (ProjectSlugTaken $taken) {
                        Notification::make()->title($taken->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(sprintf('Project "%s" created.', $project->name))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Form state arrives untyped; this is where it stops being untyped.
     */
    private function text(mixed $value, string $default = ''): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
