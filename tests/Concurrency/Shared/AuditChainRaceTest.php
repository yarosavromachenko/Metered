<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Audit\ChainVerifier;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Audit\ChainHash;
use Metered\Shared\Domain\Identifier\IdentifierGenerator;
use Metered\Shared\Domain\Identifier\Uuid;
use Spatie\Fork\Fork;

/*
 * Separate processes with their own connections, committing — the only way a
 * lock held by one writer can be seen to hold another up.
 *
 * Each run uses organizations of its own, and removes their chains after. A
 * whole chain removed is not a broken one: nothing else links to it.
 */

/**
 * @return array{string, string}
 */
function raceOrganizations(): array
{
    $ids = app(IdentifierGenerator::class);

    return [$ids->generate()->value, $ids->generate()->value];
}

function recordFor(string $organization, string $action): void
{
    app(AuditLogger::class)->record(new AuditEntry(
        organizationId: Uuid::fromString($organization),
        actor: 'user:race',
        action: $action,
        subjectType: 'invoice',
        subjectId: 'inv-race',
        payload: [],
        occurredAt: new DateTimeImmutable(),
    ));
}

it('does not make one organization wait for another’s audit entry', function (): void {
    [$acme, $globex] = raceOrganizations();

    try {
        /** @var list<float> $seconds */
        $seconds = Fork::new()->run(
            static function () use ($acme): float {
                DB::purge(testConnection());

                // Holds Acme's chain for two seconds: the entry is written, the
                // transaction around it is not yet committed.
                DB::transaction(static function () use ($acme): void {
                    recordFor($acme, 'invoice.finalized');
                    usleep(2_000_000);
                });

                return 0.0;
            },
            static function () use ($globex): float {
                DB::purge(testConnection());
                usleep(500_000);

                $started = microtime(true);
                recordFor($globex, 'invoice.finalized');

                return microtime(true) - $started;
            },
        );

        // Before chains were per organization, this waited out Acme's lock.
        expect($seconds[1])->toBeLessThan(1.0);
    } finally {
        DB::table('audit_log')->whereIn('organization_id', [$acme, $globex])->delete();
    }
});

it('keeps one organization’s chain whole under concurrent writers', function (): void {
    [$acme] = raceOrganizations();

    try {
        Fork::new()->run(...array_map(
            static fn(int $index): Closure => static function () use ($acme, $index): void {
                DB::purge(testConnection());
                recordFor($acme, 'invoice.finalized.' . $index);
            },
            range(1, 12),
        ));

        $links = DB::table('audit_log')->where('organization_id', $acme)->orderBy('sequence')->get(['prev_hash', 'hash']);
        $expected = ChainHash::GENESIS;
        $linked = 0;

        foreach ($links as $link) {
            $values = get_object_vars($link);

            if (($values['prev_hash'] ?? null) === $expected) {
                $linked++;
            }

            $expected = $values['hash'] ?? null;
        }

        expect($links)->toHaveCount(12)
            ->and($linked)->toBe(12)
            ->and(app(ChainVerifier::class)->verify()->intact)->toBeTrue();
    } finally {
        DB::table('audit_log')->where('organization_id', $acme)->delete();
    }
});
