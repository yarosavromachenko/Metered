<?php

declare(strict_types=1);

namespace Metered\Shared\Application\Audit;

interface ChainVerifier
{
    public function verify(): VerificationResult;
}
