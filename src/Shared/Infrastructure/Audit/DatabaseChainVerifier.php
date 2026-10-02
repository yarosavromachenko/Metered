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
 * Finds the first altered entry, broken link or missing row in each chain.
 * One pass in sequence order over all chains (ADR-0020), keeping only each
 * chain's last hash.
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
        // All pages from one snapshot. Under READ COMMITTED, entries of other
        // chains committing between pages could be skipped or read twice.
        $connection = $this->db->connection();

        // Inside a caller's transaction the isolation level can't be changed.
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
