<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Metered\Shared\Application\Audit\AuditLogger;
use Metered\Shared\Application\Audit\ChainVerifier;
use Metered\Shared\Domain\Audit\AuditEntry;
use Metered\Shared\Domain\Audit\ChainHash;
use Metered\Shared\Infrastructure\Persistence\RowReader;
use Psr\Clock\ClockInterface;

function record(string $action, string $actor = 'user:test'): void
{
    app(AuditLogger::class)->record(new AuditEntry(
        actor: $actor,
        action: $action,
        subjectType: 'invoice',
        subjectId: 'inv-117',
        payload: ['reason' => 'because'],
        occurredAt: app(ClockInterface::class)->now(),
    ));
}

/**
 * Alters the log behind the application's back.
 *
 * In this container the connection role is a superuser, which bypasses the
 * revoked privileges — which is exactly why the chain exists. Permissions
 * discourage tampering; the chain is what detects it when somebody has enough
 * access to try.
 */
/**
 * @param  callable():void  $change
 */
function tamper(callable $change): void
{
    $change();
}

/**
 * The sequence numbers currently in the log, in order.
 *
 * Read rather than assumed: a PostgreSQL sequence is not rolled back with the
 * transaction around a test, so the first entry of the second test is not
 * number one. Hard-coding them produced a test that quietly matched no rows
 * and passed for the wrong reason.
 *
 * @return list<int>
 */
function sequences(): array
{
    $sequences = [];

    foreach (DB::table('audit_log')->orderBy('sequence')->pluck('sequence')->all() as $sequence) {
        $sequences[] = (int) (is_scalar($sequence) ? $sequence : 0);
    }

    return $sequences;
}

it('links each entry to the one before it, starting from genesis', function (): void {
    record('invoice.finalized');
    record('invoice.paid');
    record('invoice.voided');

    $chain = [];

    foreach (DB::table('audit_log')->orderBy('sequence')->get(['prev_hash', 'hash']) as $entry) {
        $values = get_object_vars($entry);
        $chain[] = [
            'prev' => RowReader::string($values['prev_hash'] ?? null, 'prev_hash'),
            'hash' => RowReader::string($values['hash'] ?? null, 'hash'),
        ];
    }

    expect($chain)->toHaveCount(3)
        ->and($chain[0]['prev'])->toBe(ChainHash::GENESIS)
        ->and($chain[1]['prev'])->toBe($chain[0]['hash'])
        ->and($chain[2]['prev'])->toBe($chain[1]['hash']);
});

it('verifies an untouched chain', function (): void {
    record('invoice.finalized');
    record('invoice.paid');

    $result = app(ChainVerifier::class)->verify();

    expect($result->intact)->toBeTrue()
        ->and($result->entriesChecked)->toBe(2)
        ->and($result->brokenAtSequence)->toBeNull();
});

it('verifies an empty chain', function (): void {
    $result = app(ChainVerifier::class)->verify();

    expect($result->intact)->toBeTrue()
        ->and($result->entriesChecked)->toBe(0);
});

it('has UPDATE and DELETE revoked on the table', function (): void {
    $granted = DB::table('information_schema.role_table_grants')
        ->where('table_name', 'audit_log')
        ->whereIn('privilege_type', ['UPDATE', 'DELETE', 'TRUNCATE'])
        ->pluck('privilege_type')
        ->all();

    // The first line of defence, and only the first: a role with enough
    // privilege — a superuser, or whoever can GRANT to themselves — bypasses
    // this. That is precisely why the entries are chained, and why the next
    // tests tamper successfully and are caught anyway.
    expect($granted)->toBe([]);
});

it('catches an entry that was altered after it was written', function (): void {
    record('invoice.finalized');
    record('invoice.paid');
    record('invoice.voided');

    [, $second] = sequences();

    tamper(static function () use ($second): void {
        DB::table('audit_log')->where('sequence', $second)->update(['action' => 'invoice.refunded']);
    });

    $result = app(ChainVerifier::class)->verify();

    expect($result->intact)->toBeFalse()
        ->and($result->brokenAtSequence)->toBe($second)
        ->and($result->reason)->toContain('altered')
        // One valid entry was checked before the break: verification names the
        // first broken link rather than counting the tail behind it.
        ->and($result->entriesChecked)->toBe(1);
});

it('catches an entry that was removed', function (): void {
    record('invoice.finalized');
    record('invoice.paid');
    record('invoice.voided');

    [, $second] = sequences();

    tamper(static function () use ($second): void {
        DB::table('audit_log')->where('sequence', $second)->delete();
    });

    $result = app(ChainVerifier::class)->verify();

    expect($result->intact)->toBeFalse()
        ->and($result->reason)->toContain('does not follow');
});

it('catches a hash rewritten to match altered contents', function (): void {
    record('invoice.finalized');
    record('invoice.paid');

    [$first, $second] = sequences();

    tamper(static function () use ($first): void {
        // The thorough forger: change the entry and its own hash. The next
        // entry still points at the old one, so the chain gives it away.
        $row = DB::table('audit_log')->where('sequence', $first)->first();
        $values = $row === null ? [] : get_object_vars($row);

        $forged = ChainHash::compute(
            RowReader::string($values['prev_hash'] ?? null, 'prev_hash'),
            'user:test',
            'invoice.refunded',
            'invoice',
            'inv-117',
            ['reason' => 'because'],
            new DateTimeImmutable(RowReader::string($values['occurred_at'] ?? null, 'occurred_at')),
        );

        DB::table('audit_log')->where('sequence', $first)->update([
            'action' => 'invoice.refunded',
            'hash' => $forged,
        ]);
    });

    $result = app(ChainVerifier::class)->verify();

    expect($result->intact)->toBeFalse()
        ->and($result->brokenAtSequence)->toBe($second)
        ->and($result->reason)->toContain('does not follow');
});

it('reports the outcome through the console command', function (): void {
    record('invoice.finalized');

    expect(Artisan::call('audit:verify'))->toBe(0);

    [$first] = sequences();

    tamper(static function () use ($first): void {
        DB::table('audit_log')->where('sequence', $first)->update(['actor' => 'somebody else']);
    });

    expect(Artisan::call('audit:verify'))->toBe(1);
});
