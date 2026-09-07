<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\Projects\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Tenancy\Presentation\Filament\Actions\CreateProjectAction;
use Metered\Tenancy\Presentation\Filament\Actions\ResetDemoDataAction;
use Metered\Tenancy\Presentation\Filament\Resources\Projects\ProjectResource;

final class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateProjectAction::make(), ResetDemoDataAction::make()];
    }
}
