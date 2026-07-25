<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\PlanVersions\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Billing\Presentation\Filament\Resources\PlanVersions\PlanVersionResource;

final class ListPlanVersions extends ListRecords
{
    protected static string $resource = PlanVersionResource::class;
}
