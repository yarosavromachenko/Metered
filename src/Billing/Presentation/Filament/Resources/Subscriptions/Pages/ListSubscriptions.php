<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Subscriptions\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Billing\Presentation\Filament\Actions\StartSubscriptionAction;
use Metered\Billing\Presentation\Filament\Resources\Subscriptions\SubscriptionResource;

final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [StartSubscriptionAction::make()];
    }
}
