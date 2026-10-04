<?php

declare(strict_types=1);

namespace Metered\Usage\Application\Ingestion;

use Metered\Usage\Domain\Rejection;

interface RejectionLog
{
    /**
     * @param  list<Rejection>  $rejections
     */
    public function record(array $rejections): void;
}
