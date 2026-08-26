<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Resources\Endpoints\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Webhooks\Presentation\Filament\Actions\RegisterEndpointAction;
use Metered\Webhooks\Presentation\Filament\Resources\Endpoints\WebhookEndpointResource;

final class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [RegisterEndpointAction::make()];
    }
}
