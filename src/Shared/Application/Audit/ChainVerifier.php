<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

/**
 * Walks the audit chain and reports the first place it breaks.
 */
interface ChainVerifier
{
    public function verify(): VerificationResult;
}
