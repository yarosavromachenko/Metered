<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Aggregates\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Usage\Presentation\Filament\Resources\Aggregates\AggregateResource;

final class ListAggregates extends ListRecords
{
    protected static string $resource = AggregateResource::class;
}
