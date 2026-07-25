<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Plans\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Billing\Presentation\Filament\Actions\CreatePlanAction;
use Metered\Billing\Presentation\Filament\Resources\Plans\PlanResource;

final class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreatePlanAction::make()];
    }
}
