<?php

declare(strict_types=1);

namespace Metered\Shared\Infrastructure\Audit;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Metered\Shared\Application\Audit\ChainVerifier;
use Metered\Shared\Application\Audit\VerificationResult;
use Metered\Shared\Domain\Audit\ChainHash;
use Metered\Shared\Infrastructure\Persistence\RowReader;

/**
 * Recomputes every link and stops at the first that does not match.
 *
 * Three things can be wrong, and the result says which: an entry whose
 * contents no longer produce its stored hash, an entry that does not point at
 * its predecessor, and a gap where a row was removed.
 *
 * Every organization's chain is checked in the same pass (ADR-0020): the rows
 * are read once in sequence order, and each chain's last hash is remembered,
 * so memory grows with the number of organizations, not of entries.
 */
final readonly class DatabaseChainVerifier implements ChainVerifier
{
    private const string PLATFORM = '';

    public function __construct(
        private DatabaseManager $db,
        private int $chunkSize = 1000,
    ) {}

    public function verify(): VerificationResult
    {
        // One snapshot for every page. Chains commit independently, so an
        // entry can become visible after the one written next to it in another
        // chain; read in pages under READ COMMITTED, a page could miss it, or
        // an offset shift read a row twice, and an untouched chain would look
        // broken. In one snapshot a visible entry's predecessor is visible
        // too: the chain's lock made it commit first.
        $connection = $this->db->connection();

        // Inside a caller's transaction the isolation level is the caller's to
        // set, and can no longer be changed.
        if ($connection->transactionLevel() > 0) {
            return $this->walk();
        }

        return $connection->transaction(function () use ($connection): VerificationResult {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->walk();
        });
    }

    private function walk(): VerificationResult
    {
        /** @var array<string, array{hash: string, sequence: int}> $last each chain's newest entry so far */
        $last = [];
        $checked = 0;
        $broken = null;

        $this->db->connection()
            ->table('audit_log')
            ->orderBy('sequence')
            ->chunk($this->chunkSize, function (iterable $rows) use (&$last, &$checked, &$broken): bool {
                foreach ($rows as $row) {
                    if (! is_object($row)) {
                        continue;
                    }

                    $values = get_object_vars($row);
                    $organizationId = $this->nullableString($values['organization_id'] ?? null, 'organization_id');
                    $chain = $organizationId ?? self::PLATFORM;
                    $sequence = RowReader::int($values['sequence'] ?? null, 'sequence');
                    $storedPrevious = RowReader::string($values['prev_hash'] ?? null, 'prev_hash');
                    $storedHash = RowReader::string($values['hash'] ?? null, 'hash');
                    $predecessor = $last[$chain] ?? null;

                    if ($storedPrevious !== ($predecessor['hash'] ?? ChainHash::GENESIS)) {
                        $broken = VerificationResult::broken(
                            $checked,
                            $sequence,
                            $predecessor === null
                                ? sprintf('%s does not start at the genesis hash', $this->describe($organizationId))
                                : sprintf('entry %d does not follow entry %d in %s', $sequence, $predecessor['sequence'], $this->describe($organizationId)),
                            $organizationId,
                        );

                        return false;
                    }

                    $recomputed = ChainHash::compute(
                        $storedPrevious,
                        RowReader::string($values['actor'] ?? null, 'actor'),
                        RowReader::string($values['action'] ?? null, 'action'),
                        RowReader::string($values['subject_type'] ?? null, 'subject_type'),
                        $this->nullableString($values['subject_id'] ?? null, 'subject_id'),
                        RowReader::jsonObject($values['payload'] ?? null, 'payload'),
                        new DateTimeImmutable(RowReader::string($values['occurred_at'] ?? null, 'occurred_at')),
                        $organizationId,
                    );

                    if ($recomputed !== $storedHash) {
                        $broken = VerificationResult::broken(
                            $checked,
                            $sequence,
                            sprintf('entry %d in %s has been altered since it was written', $sequence, $this->describe($organizationId)),
                            $organizationId,
                        );

                        return false;
                    }

                    $last[$chain] = ['hash' => $storedHash, 'sequence' => $sequence];
                    $checked++;
                }

                return true;
            });

        return $broken ?? VerificationResult::intact($checked);
    }

    private function describe(?string $organizationId): string
    {
        return $organizationId === null ? 'the platform chain' : 'the chain of organization ' . $organizationId;
    }

    private function nullableString(mixed $value, string $column): ?string
    {
        return $value === null ? null : RowReader::string($value, $column);
    }
}
