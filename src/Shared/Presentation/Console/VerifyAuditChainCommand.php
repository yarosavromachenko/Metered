<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Metered\Shared\Application\Audit\ChainVerifier;

/**
 * Verifies the audit chain, on a schedule and on demand.
 *
 * Scheduled, because a broken chain found during the incident it would have
 * explained is found too late.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Walk the audit log chain and report the first broken link';

    public function handle(ChainVerifier $verifier): int
    {
        $result = $verifier->verify();

        if ($result->intact) {
            $this->components->info(sprintf('Audit chain intact: %d entries verified.', $result->entriesChecked));

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            'Audit chain broken at sequence %d after %d valid entries: %s',
            $result->brokenAtSequence ?? 0,
            $result->entriesChecked,
            $result->reason ?? 'unknown reason',
        ));

        return self::FAILURE;
    }
}
