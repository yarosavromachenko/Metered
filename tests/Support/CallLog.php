<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Records, in order, who was called. Shared between test handlers so that the
 * order across several of them is observable.
 */
final class CallLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $name): void
    {
        $this->entries[] = $name;
    }
}
