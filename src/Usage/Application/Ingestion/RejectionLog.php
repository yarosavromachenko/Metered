<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Usage\Domain\Rejection;

/**
 * Where events that were not counted go.
 *
 * Written in bulk, because rejections arrive the way they are produced: a
 * client whose deploy renamed a meter sends five hundred of them at once.
 */
interface RejectionLog
{
    /**
     * @param  list<Rejection>  $rejections
     */
    public function record(array $rejections): void;
}
