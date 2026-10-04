<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\ReadinessCheck;

/**
 * Fails at the same depth at which ingestion starts answering 503.
 */
final readonly class BacklogCheck implements ReadinessCheck
{
    public function __construct(
        private StreamDepth $depth,
        private int $threshold,
    ) {}

    public function name(): string
    {
        return 'usage_backlog';
    }

    public function check(): CheckResult
    {
        $pending = $this->depth->pending();
        $detail = sprintf('%d pending of %d', $pending, $this->threshold);

        return $pending >= $this->threshold
            ? CheckResult::fail($detail)
            : CheckResult::pass($detail);
    }
}
