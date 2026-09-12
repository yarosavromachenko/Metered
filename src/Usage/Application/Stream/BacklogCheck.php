<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Stream;

use Metered\Shared\Application\Health\CheckResult;
use Metered\Shared\Application\Health\ReadinessCheck;

/**
 * The ingestion backlog is below the depth at which ingestion sheds load.
 *
 * The same threshold and the same comparison as the backpressure decision:
 * an instance that would answer every ingestion request with 503 is not
 * ready, and it should say so before a load balancer sends it more.
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
