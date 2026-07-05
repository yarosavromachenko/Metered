<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Events\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Usage\Presentation\Filament\Resources\Events\UsageEventResource;

final class ListUsageEvents extends ListRecords
{
    protected static string $resource = UsageEventResource::class;
}
