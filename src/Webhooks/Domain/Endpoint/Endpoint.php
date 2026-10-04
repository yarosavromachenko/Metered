<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Endpoint;

use DateTimeImmutable;
use Metered\Shared\Domain\Identifier\Uuid;
use Metered\Shared\Domain\Tenant\TenantContext;
use Metered\Webhooks\Domain\Exception\InvalidEndpoint;
use Metered\Webhooks\Domain\Signing\SecretKey;

/**
 * After a rotation, deliveries are signed with both secrets until the old
 * one's grace period ends (ADR-0011).
 */
final readonly class Endpoint
{
    public const int DESCRIPTION_LIMIT = 255;

    /**
     * @param non-empty-list<EventType> $eventTypes
     */
    private function __construct(
        public Uuid $id,
        public TenantContext $tenant,
        public EndpointUrl $url,
        public string $description,
        public array $eventTypes,
        public SecretKey $secret,
        public ?SecretKey $previousSecret,
        public ?DateTimeImmutable $previousSecretExpiresAt,
        public bool $enabled,
        public CircuitBreaker $breaker,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param list<EventType> $eventTypes
     */
    public static function register(
        Uuid $id,
        TenantContext $tenant,
        EndpointUrl $url,
        string $description,
        array $eventTypes,
        SecretKey $secret,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $tenant, $url, self::description($description), self::eventTypes($eventTypes), $secret, null, null, true, CircuitBreaker::closed(), $at);
    }

    /**
     * @param non-empty-list<EventType> $eventTypes
     *
     * @internal for the repository
     */
    public static function restore(
        Uuid $id,
        TenantContext $tenant,
        EndpointUrl $url,
        string $description,
        array $eventTypes,
        SecretKey $secret,
        ?SecretKey $previousSecret,
        ?DateTimeImmutable $previousSecretExpiresAt,
        bool $enabled,
        CircuitBreaker $breaker,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $tenant, $url, $description, $eventTypes, $secret, $previousSecret, $previousSecretExpiresAt, $enabled, $breaker, $createdAt);
    }

    public function listensTo(EventType $type): bool
    {
        return $this->enabled && in_array($type, $this->eventTypes, true);
    }

    /**
     * Current secret, plus the previous one during its grace period.
     *
     * @return non-empty-list<SecretKey>
     */
    public function signingSecrets(DateTimeImmutable $now): array
    {
        if ($this->previousSecret instanceof SecretKey && $this->previousSecretExpiresAt > $now) {
            return [$this->secret, $this->previousSecret];
        }

        return [$this->secret];
    }

    public function rotate(SecretKey $secret, DateTimeImmutable $now, int $graceSeconds): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $this->url,
            $this->description,
            $this->eventTypes,
            $secret,
            $this->secret,
            $now->modify(sprintf('+%d seconds', $graceSeconds)),
            $this->enabled,
            $this->breaker,
            $this->createdAt,
        );
    }

    /**
     * @param list<EventType> $eventTypes
     */
    public function reconfigure(EndpointUrl $url, string $description, array $eventTypes, bool $enabled): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $url,
            self::description($description),
            self::eventTypes($eventTypes),
            $this->secret,
            $this->previousSecret,
            $this->previousSecretExpiresAt,
            $enabled,
            // New URL: reset the breaker.
            $url->value === $this->url->value ? $this->breaker : CircuitBreaker::closed(),
            $this->createdAt,
        );
    }

    public function withBreaker(CircuitBreaker $breaker): self
    {
        return new self(
            $this->id,
            $this->tenant,
            $this->url,
            $this->description,
            $this->eventTypes,
            $this->secret,
            $this->previousSecret,
            $this->previousSecretExpiresAt,
            $this->enabled,
            $breaker,
            $this->createdAt,
        );
    }

    private static function description(string $description): string
    {
        $description = trim($description);

        if (mb_strlen($description) > self::DESCRIPTION_LIMIT) {
            throw InvalidEndpoint::descriptionTooLong(self::DESCRIPTION_LIMIT);
        }

        return $description;
    }

    /**
     * @param list<EventType> $eventTypes
     *
     * @return non-empty-list<EventType>
     */
    private static function eventTypes(array $eventTypes): array
    {
        $unique = [];

        foreach (EventType::cases() as $type) {
            if (in_array($type, $eventTypes, true)) {
                $unique[] = $type;
            }
        }

        if ($unique === []) {
            throw InvalidEndpoint::noEvents();
        }

        return $unique;
    }
}
