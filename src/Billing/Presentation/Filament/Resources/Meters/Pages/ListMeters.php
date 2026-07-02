<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Meters\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Billing\Presentation\Filament\Actions\DefineMeterAction;
use Metered\Billing\Presentation\Filament\Resources\Meters\MeterResource;

final class ListMeters extends ListRecords
{
    protected static string $resource = MeterResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [DefineMeterAction::make()];
    }
}
