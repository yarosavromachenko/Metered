<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Resources\Deliveries\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Metered\Webhooks\Presentation\Filament\Actions\ReplayDeliveryAction;
use Metered\Webhooks\Presentation\Filament\Resources\Deliveries\WebhookDeliveryResource;

final class ViewWebhookDelivery extends ViewRecord
{
    protected static string $resource = WebhookDeliveryResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [ReplayDeliveryAction::make()];
    }
}
