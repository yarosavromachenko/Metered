<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Filament\Resources\Rejections\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Usage\Presentation\Filament\Resources\Rejections\RejectionResource;

final class ListRejections extends ListRecords
{
    protected static string $resource = RejectionResource::class;
}
