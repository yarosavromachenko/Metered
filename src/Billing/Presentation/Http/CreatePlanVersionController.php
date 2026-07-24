<?php

declare(strict_types=1);

namespace Metered\Billing\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Metered\Billing\Application\Command\AddPrice;
use Metered\Billing\Application\Command\AddPriceHandler;
use Metered\Billing\Application\Command\DraftPlanVersion;
use Metered\Billing\Application\Command\DraftPlanVersionHandler;
use Metered\Billing\Application\Command\PublishPlanVersion;
use Metered\Billing\Application\Command\PublishPlanVersionHandler;
use Metered\Billing\Domain\Exception\InvalidMeterCode;
use Metered\Billing\Domain\Meter;
use Metered\Billing\Domain\MeterCode;
use Metered\Billing\Domain\MeterRepository;
use Metered\Billing\Domain\Period\BillingInterval;
use Metered\Billing\Domain\Plan\PlanVersion;
use Metered\Billing\Domain\Plan\PlanVersionRepository;
use Metered\Shared\Application\Transaction\Transactions;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Shared\Presentation\Http\TenantRequest;

/**
 * Creates a plan version with its prices in one request, published unless
 * `publish` is false.
 *
 * Three use cases run here — draft, price, publish — each authorized and
 * audited on its own, inside one transaction: a price the domain refuses
 * halfway through leaves no half-built draft behind.
 */
final readonly class CreatePlanVersionController
{
    public function __construct(
        private DraftPlanVersionHandler $draft,
        private AddPriceHandler $addPrice,
        private PublishPlanVersionHandler $publish,
        private PlanVersionRepository $versions,
        private MeterRepository $meters,
        private Transactions $transactions,
    ) {}

    public function __invoke(Request $request, string $plan): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);
        $actor = ApiCaller::actor($request);

        /** @var array{interval: string, prices: list<array<string, mixed>>, publish?: bool} $input */
        $input = Validator::make($request->all(), [
            'interval' => ['required', 'string', 'in:month,year'],
            'prices' => ['required', 'array', 'min:1', 'max:20'],
            'publish' => ['sometimes', 'boolean'],
        ] + PriceInput::rules('prices.*'))->validate();

        $version = $this->transactions->run(function () use ($tenant, $actor, $input, $plan): PlanVersion {
            $draft = $this->draft->handle(new DraftPlanVersion(
                $tenant,
                Uuid::fromString($plan),
                BillingInterval::from($input['interval']),
                $actor,
            ));

            foreach ($input['prices'] as $index => $entry) {
                $this->addPrice->handle(new AddPrice(
                    $tenant,
                    $draft->id,
                    PriceInput::model($entry, $draft->currency),
                    $this->meterId($tenant, $entry, $index),
                    $actor,
                ));
            }

            return ($input['publish'] ?? true)
                ? $this->publish->handle(new PublishPlanVersion($tenant, $draft->id, $actor))
                : $this->versions->find($tenant, $draft->id) ?? $draft;
        });

        return new JsonResponse(CatalogJson::version($version, MeterCodes::of($this->meters->listFor($tenant))), 201);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function meterId(TenantContext $tenant, array $entry, int $index): ?Uuid
    {
        $code = $entry['meter'] ?? null;

        if (! is_string($code)) {
            return null;
        }

        try {
            $meter = $this->meters->findByCode($tenant, MeterCode::fromString($code));
        } catch (InvalidMeterCode) {
            $meter = null;
        }

        if (! $meter instanceof Meter) {
            throw ValidationException::withMessages([
                sprintf('prices.%d.meter', $index) => sprintf('This project has no meter "%s".', $code),
            ]);
        }

        return $meter->id;
    }
}
