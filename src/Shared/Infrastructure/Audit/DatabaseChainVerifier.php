<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Audit;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Audit\ChainVerifier;
use Metered\Shared\Application\Audit\VerificationResult;
use Metered\Shared\Domain\Audit\ChainHash;
use Metered\Shared\Infrastructure\Outbox\RowReader;

/**
 * Recomputes every link and stops at the first that does not match.
 *
 * Three things can be wrong, and the result says which: an entry whose
 * contents no longer produce its stored hash, an entry that does not point at
 * its predecessor, and a gap where a row was removed.
 */
final readonly class DatabaseChainVerifier implements ChainVerifier
{
    public function __construct(
        private DatabaseManager $db,
        private int $chunkSize = 1000,
    ) {}

    public function verify(): VerificationResult
    {
        $expectedPrevious = ChainHash::GENESIS;
        $checked = 0;
        $lastSequence = 0;
        $broken = null;

        $this->db->connection()
            ->table('audit_log')
            ->orderBy('sequence')
            ->chunk($this->chunkSize, function (iterable $rows) use (&$expectedPrevious, &$checked, &$lastSequence, &$broken): bool {
                foreach ($rows as $row) {
                    if (! is_object($row)) {
                        continue;
                    }

                    $values = get_object_vars($row);
                    $sequence = RowReader::int($values['sequence'] ?? null, 'sequence');
                    $storedPrevious = RowReader::string($values['prev_hash'] ?? null, 'prev_hash');
                    $storedHash = RowReader::string($values['hash'] ?? null, 'hash');

                    if ($storedPrevious !== $expectedPrevious) {
                        $broken = VerificationResult::broken(
                            $checked,
                            $sequence,
                            $lastSequence === 0
                                ? 'the chain does not start at the genesis hash'
                                : sprintf('entry %d does not follow entry %d', $sequence, $lastSequence),
                        );

                        return false;
                    }

                    $recomputed = ChainHash::compute(
                        $storedPrevious,
                        RowReader::string($values['actor'] ?? null, 'actor'),
                        RowReader::string($values['action'] ?? null, 'action'),
                        RowReader::string($values['subject_type'] ?? null, 'subject_type'),
                        $this->nullableString($values['subject_id'] ?? null),
                        RowReader::jsonObject($values['payload'] ?? null, 'payload'),
                        new DateTimeImmutable(RowReader::string($values['occurred_at'] ?? null, 'occurred_at')),
                    );

                    if ($recomputed !== $storedHash) {
                        $broken = VerificationResult::broken(
                            $checked,
                            $sequence,
                            sprintf('entry %d has been altered since it was written', $sequence),
                        );

                        return false;
                    }

                    $expectedPrevious = $storedHash;
                    $lastSequence = $sequence;
                    $checked++;
                }

                return true;
            });

        return $broken ?? VerificationResult::intact($checked);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : RowReader::string($value, 'subject_id');
    }
}
