<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Components;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Metered\Tenancy\Presentation\Filament\PanelScope;

/**
 * The control that says which project everything on screen belongs to.
 *
 * It offers only what the signed-in person can reach, and the scope it sets is
 * validated again when it is read — so a project id pushed into the component
 * by hand changes nothing.
 *
 * Switching reloads the page rather than updating tables in place. The scope
 * is read by every query on the screen, and a partial refresh would leave one
 * table showing the previous project's rows next to another showing the new
 * one's.
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
