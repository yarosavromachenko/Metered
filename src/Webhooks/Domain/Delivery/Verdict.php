<?php

declare(strict_types=1);

namespace Metered\Webhooks\Domain\Delivery;

enum Verdict
{
    case Delivered;
    case Retry;
    case GiveUp;
}
