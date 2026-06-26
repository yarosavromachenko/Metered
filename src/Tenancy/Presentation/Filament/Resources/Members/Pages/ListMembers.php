<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\Members\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Tenancy\Presentation\Filament\Resources\Members\MemberResource;

final class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;
}
