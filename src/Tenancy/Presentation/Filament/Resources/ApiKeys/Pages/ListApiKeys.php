<?php

declare(strict_types=1);

namespace Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Tenancy\Presentation\Filament\Actions\IssueKeyAction;
use Metered\Tenancy\Presentation\Filament\Resources\ApiKeys\ApiKeyResource;

final class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [IssueKeyAction::make()];
    }
}
