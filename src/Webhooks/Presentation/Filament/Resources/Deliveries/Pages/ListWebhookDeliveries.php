<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Filament\Resources\Deliveries\Pages;

use Filament\Resources\Pages\ListRecords;
use Metered\Webhooks\Presentation\Filament\Resources\Deliveries\WebhookDeliveryResource;

final class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;
}
