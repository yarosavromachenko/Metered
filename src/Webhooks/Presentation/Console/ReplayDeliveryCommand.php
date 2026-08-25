<?php

declare(strict_types=1);

namespace Metered\Webhooks\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Domain\Access\Actor;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Application\Command\ReplayDelivery;
use Metered\Webhooks\Application\Command\ReplayDeliveryHandler;
use Metered\Webhooks\Infrastructure\Eloquent\WebhookDelivery;

/**
 * Replays a dead or failed delivery from the command line, for an operator
 * who has its id from a log or an alert rather than a tenant's panel.
 */
final class ReplayDeliveryCommand extends Command
{
    protected $signature = 'webhooks:replay {delivery : The delivery id}';

    protected $description = 'Send a dead or failed webhook delivery again, from its first attempt';

    public function handle(ReplayDeliveryHandler $handler): int
    {
        $id = $this->argument('delivery');
        $delivery = is_string($id) && Uuid::isValid($id) ? WebhookDelivery::query()->find($id) : null;

        if (! $delivery instanceof WebhookDelivery) {
            $this->components->error('No such delivery.');

            return self::FAILURE;
        }

        $handler->handle(new ReplayDelivery(
            new TenantContext(Uuid::fromString($delivery->organization_id), Uuid::fromString($delivery->project_id)),
            Uuid::fromString($delivery->id),
            Actor::system('console:webhooks:replay'),
        ));

        $this->components->info(sprintf('Delivery %s of %s will be sent again within seconds.', $delivery->id, $delivery->event_type));

        return self::SUCCESS;
    }
}
