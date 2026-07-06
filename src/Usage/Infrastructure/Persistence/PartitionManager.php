<?php

declare(strict_types=1);

namespace Metered\Usage\Infrastructure\Persistence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use RuntimeException;
use stdClass;

/**
 * Creates the day partitions `usage_events` is written into, and drops the
 * ones past retention.
 *
 * Daily ranges, because the acceptance window is seven days: a client may
 * still send yesterday's usage, so a week of partitions has to stay writable,
 * and a week is a comfortable number of objects. Monthly ranges would make
 * each partition large enough for index maintenance to slow inserts down,
 * which is the thing partitioning was supposed to prevent (ADR-0002).
 *
 * Every operation is idempotent. This runs on a schedule, and a scheduler that
 * fires twice — a retry, an overlapping run, an operator running it by hand —
 * must be a no-op rather than an error.
 */
final readonly class PartitionManager
{
    private const string TABLE = 'usage_events';

    private const string PREFIX = 'usage_events_p';

    public function __construct(
        private DatabaseManager $db,
        private string $connection,
    ) {}

    /**
     * Makes sure every day in [today - $daysBack, today + $daysAhead] has a
     * partition, and returns the ones it had to create.
     *
     * The window reaches backwards as well as forwards because the acceptance
     * window does: an event from six days ago is legitimate, and it needs
     * somewhere to land that is not the default partition.
     *
     * @return list<string>
     */
    public function ensure(
        DateTimeImmutable $around,
        int $daysBack,
        int $daysAhead,
        bool $rescueStrandedRows = false,
    ): array {
        $day = $this->midnight($around)->sub(new DateInterval('P' . max(0, $daysBack) . 'D'));
        $last = $this->midnight($around)->add(new DateInterval('P' . max(0, $daysAhead) . 'D'));
        $created = [];

        while ($day <= $last) {
            if ($this->create($day, $rescueStrandedRows)) {
                $created[] = self::PREFIX . $day->format('Ymd');
            }

            $day = $day->add(new DateInterval('P1D'));
        }

        return $created;
    }

    /**
     * Drops every partition whose whole range is older than the given
     * instant. Detach first, then drop: detaching takes a brief lock and
     * leaves a plain table behind, so a mistake is recoverable for as long as
     * it takes to notice.
     *
     * @return list<string>
     */
    public function prune(DateTimeImmutable $before): array
    {
        $dropped = [];

        foreach ($this->partitions() as $partition) {
            if ($partition->isDefault || ! $partition->to instanceof DateTimeImmutable || $partition->to > $before) {
                continue;
            }

            $this->db->connection($this->connection)->statement(sprintf(
                'ALTER TABLE %s DETACH PARTITION %s',
                self::TABLE,
                $partition->name,
            ));
            $this->db->connection($this->connection)->statement(sprintf('DROP TABLE %s', $partition->name));

            $dropped[] = $partition->name;
        }

        return $dropped;
    }

    /**
     * Every partition the table has, oldest first, with the default last.
     *
     * @return list<Partition>
     */
    public function partitions(): array
    {
        $rows = $this->db->connection($this->connection)->select(
            <<<'SQL'
                SELECT child.relname AS name, pg_get_expr(child.relpartbound, child.oid) AS bounds
                FROM pg_inherits
                JOIN pg_class parent ON parent.oid = pg_inherits.inhparent
                JOIN pg_class child ON child.oid = pg_inherits.inhrelid
                WHERE parent.relname = ?
                ORDER BY child.relname
            SQL,
            [self::TABLE],
        );

        $partitions = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $values = get_object_vars($row);
            $name = RowReader::string($values['name'] ?? null, 'name');
            $bounds = RowReader::string($values['bounds'] ?? null, 'bounds');

            $partitions[] = $this->toPartition($name, $bounds);
        }

        return $partitions;
    }

    /**
     * @return array{partitions: int, default_rows: int}
     */
    public function health(): array
    {
        $rows = $this->db->connection($this->connection)->selectOne(
            sprintf('SELECT count(*) AS rows FROM %s', self::TABLE . '_default'),
        );

        $count = $rows instanceof stdClass ? RowReader::int(get_object_vars($rows)['rows'] ?? null, 'rows') : 0;

        return ['partitions' => count($this->partitions()), 'default_rows' => $count];
    }

    private function create(DateTimeImmutable $day, bool $rescueStrandedRows): bool
    {
        $name = self::PREFIX . $day->format('Ymd');

        if ($this->exists($name)) {
            return false;
        }

        $next = $day->add(new DateInterval('P1D'));

        if ($this->defaultHolds($day, $next)) {
            // PostgreSQL will not carve a range out from under rows that are
            // already in the default partition, so those rows have to move
            // first. That is data movement under a lock, which is a decision
            // rather than a repair the scheduler makes on its own.
            if (! $rescueStrandedRows) {
                throw new RuntimeException(sprintf(
                    'Cannot create partition %s: the default partition already holds rows for %s. '
                    . 'Run usage:partitions:ensure --rescue to move them into it.',
                    $name,
                    $day->format('Y-m-d'),
                ));
            }

            $this->rescue($name, $day, $next);

            return true;
        }

        try {
            // IF NOT EXISTS as well as the check above: two schedulers, or a
            // scheduler and an operator, may arrive at the same second.
            $this->db->connection($this->connection)->statement(sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                self::TABLE,
                $day->format('Y-m-d H:i:sP'),
                $next->format('Y-m-d H:i:sP'),
            ));
        } catch (QueryException $failure) {
            throw new RuntimeException(sprintf(
                'Could not create partition %s: %s',
                $name,
                $failure->getMessage(),
            ), (int) $failure->getCode(), previous: $failure);
        }

        return true;
    }

    /**
     * Moves a day's stranded rows out of the default partition and attaches
     * them as that day's partition.
     *
     * Copy, delete, attach — in one transaction, so a failure leaves the rows
     * where they were rather than in two places or in none. Attaching builds
     * the child indexes from the parent's, which is why the new table is
     * created empty of them.
     */
    private function rescue(string $name, DateTimeImmutable $day, DateTimeImmutable $next): void
    {
        $connection = $this->db->connection($this->connection);
        $from = $day->format('Y-m-d H:i:sP');
        $to = $next->format('Y-m-d H:i:sP');

        $connection->transaction(function () use ($connection, $name, $from, $to): void {
            $connection->statement(sprintf(
                'CREATE TABLE %s (LIKE %s INCLUDING DEFAULTS INCLUDING CONSTRAINTS)',
                $name,
                self::TABLE,
            ));

            $connection->statement(sprintf(
                'WITH moved AS (
                     DELETE FROM %s_default WHERE occurred_at >= ? AND occurred_at < ? RETURNING *
                 )
                 INSERT INTO %s SELECT * FROM moved',
                self::TABLE,
                $name,
            ), [$from, $to]);

            $connection->statement(sprintf(
                "ALTER TABLE %s ATTACH PARTITION %s FOR VALUES FROM ('%s') TO ('%s')",
                self::TABLE,
                $name,
                $from,
                $to,
            ));
        });
    }

    private function defaultHolds(DateTimeImmutable $day, DateTimeImmutable $next): bool
    {
        $row = $this->db->connection($this->connection)->selectOne(
            sprintf(
                'SELECT 1 AS found FROM %s_default WHERE occurred_at >= ? AND occurred_at < ? LIMIT 1',
                self::TABLE,
            ),
            [$day->format('Y-m-d H:i:sP'), $next->format('Y-m-d H:i:sP')],
        );

        return $row !== null;
    }

    private function exists(string $name): bool
    {
        return $this->db->connection($this->connection)->selectOne('SELECT 1 FROM pg_class WHERE relname = ?', [$name]) !== null;
    }

    private function toPartition(string $name, string $bounds): Partition
    {
        if (str_contains($bounds, 'DEFAULT')) {
            return new Partition($name, null, null, true);
        }

        // FOR VALUES FROM ('2026-09-22 00:00:00+00') TO ('2026-09-23 00:00:00+00')
        preg_match_all("/'([^']+)'/", $bounds, $matches);
        $edges = $matches[1];

        return new Partition(
            $name,
            isset($edges[0]) ? new DateTimeImmutable($edges[0], new DateTimeZone('UTC')) : null,
            isset($edges[1]) ? new DateTimeImmutable($edges[1], new DateTimeZone('UTC')) : null,
            false,
        );
    }

    private function midnight(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }
}
