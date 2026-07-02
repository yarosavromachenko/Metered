<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Filament\Resources\Customers\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Metered\Billing\Presentation\Filament\Actions\RegisterCustomerAction;
use Metered\Billing\Presentation\Filament\Resources\Customers\CustomerResource;

final class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [RegisterCustomerAction::make()];
    }
}
