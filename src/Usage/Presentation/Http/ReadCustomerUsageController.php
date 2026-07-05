<?php

declare(strict_types=1);

namespace Metered\Usage\Presentation\Http;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Metered\Billing\Application\Contract\CustomerDescriptor;
use Metered\Billing\Application\Contract\CustomerDirectory;
use Metered\Shared\Presentation\Http\Problem;
use Metered\Shared\Presentation\Http\TenantRequest;
use Metered\Usage\Infrastructure\Persistence\UsageSummaryReader;
use Psr\Clock\ClockInterface;

/**
 * `GET /api/v1/customers/{reference}/usage` — what this customer has used.
 *
 * Read from the aggregates, never from the raw events: a period's worth of
 * events for one customer is a table scan whose cost grows with history,
 * which is the whole reason aggregates exist (ADR-0004).
 *
 * An unknown customer is a 404 rather than an empty summary. "No usage" and
 * "no such customer" are different answers, and a client integrating against
 * this needs to be able to tell them apart.
 */
final readonly class ReadCustomerUsageController
{
    public function __construct(
        private UsageSummaryReader $usage,
        private CustomerDirectory $customers,
        private ValidatorFactory $validator,
        private ClockInterface $clock,
    ) {}

    public function __invoke(Request $request, string $reference): JsonResponse
    {
        $tenant = TenantRequest::tenant($request);

        $this->validator->make($request->query(), [
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'meter' => ['sometimes', 'string', 'max:64'],
        ])->validate();

        if (! $this->customers->find($tenant, $reference) instanceof CustomerDescriptor) {
            return Problem::response(
                'customer-not-found',
                'No such customer',
                404,
                sprintf('No customer in this project is registered as "%s".', $reference),
                Problem::instanceFor($request),
            );
        }

        $now = $this->clock->now();
        // A day, because that is the question asked most often and the one
        // whose answer is cheapest. A billing period is asked for explicitly.
        $from = $this->instant($request, 'from') ?? $now->sub(new DateInterval('P1D'));
        $to = $this->instant($request, 'to') ?? $now;

        $meter = $request->query('meter');

        return new JsonResponse([
            'customer_ref' => $reference,
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
            'meters' => $this->usage->forCustomer(
                $tenant,
                $reference,
                $from,
                $to,
                is_string($meter) && $meter !== '' ? strtolower(trim($meter)) : null,
            ),
        ]);
    }

    private function instant(Request $request, string $parameter): ?DateTimeImmutable
    {
        $value = $request->query($parameter);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        return new DateTimeImmutable($value, $utc)->setTimezone($utc);
    }
}
