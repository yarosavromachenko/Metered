<?php

declare(strict_types=1);

namespace Metered\Billing\Domain\Exception;

use DateTimeImmutable;
use Metered\Shared\Domain\Exception\DomainException;

final class SubscriptionChangeRefused extends DomainException
{
    public static function draftVersion(int $number): self
    {
        return new self(sprintf('Plan version %d is a draft; only a published version can be subscribed to.', $number));
    }

    public static function otherProject(): self
    {
        return new self('That plan version belongs to another project.');
    }

    public static function sameVersion(): self
    {
        return new self('The subscription is already on that version.');
    }

    public static function currencyChanged(string $current, string $other): self
    {
        return new self(sprintf(
            'The subscription is billed in %s; a version priced in %s would need a new subscription.',
            $current,
            $other,
        ));
    }

    public static function intervalChanged(string $current, string $other): self
    {
        return new self(sprintf(
            'The subscription is billed every %s; a version billed every %s would need a new subscription.',
            $current,
            $other,
        ));
    }

    public static function changeScheduled(DateTimeImmutable $at): self
    {
        return new self(sprintf('A plan change is already scheduled for %s.', $at->format('Y-m-d H:i')));
    }

    public static function startsInTheFuture(): self
    {
        return new self('A subscription starts now or in the past; one that starts later is started then.');
    }

    public static function backdatedTooFar(int $days): self
    {
        return new self(sprintf('A subscription may start at most %d days in the past.', $days));
    }

    public static function notActive(string $status): self
    {
        return new self(sprintf('The subscription %s.', $status === 'canceled' ? 'is canceled' : 'is pending cancellation'));
    }
}
