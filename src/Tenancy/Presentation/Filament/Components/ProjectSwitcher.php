<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Components;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * Lists reachable projects only; PanelScope validates the choice again.
 * Switching reloads the page so no table keeps the old project's rows.
 */
final class ProjectSwitcher extends Component
{
    public string $projectId = '';

    public function mount(): void
    {
        $this->projectId = app(PanelScope::class)->project()?->id->value ?? '';
    }

    public function updatedProjectId(string $value): void
    {
        if (app(PanelScope::class)->switchTo($value)) {
            $this->redirect(request()->headers->get('referer') ?? '/admin');
        }
    }

    public function render(): View
    {
        $scope = app(PanelScope::class);
        $options = [];

        foreach ($scope->available() as $project) {
            $options[$project->id->value] = sprintf('%s · %s', $project->slug->value, $project->environment->value);
        }

        return view('tenancy::filament.project-switcher', ['options' => $options]);
    }
}
