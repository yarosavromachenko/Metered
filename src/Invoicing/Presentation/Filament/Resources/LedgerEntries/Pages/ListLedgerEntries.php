<?php

declare(strict_types=1);

namespace Metered\Invoicing\Presentation\Filament\Resources\LedgerEntries\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Invoicing\Presentation\Filament\Resources\LedgerEntries\LedgerEntryResource;

final class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;
}
