<?php

declare(strict_types=1);

/**
 * Settings that are only correct together.
 *
 * Each is an environment variable and can be changed alone, which is exactly
 * how a pair like this comes apart: nothing fails, and the guarantee they
 * made together quietly stops holding.
 */
it('remembers a claim for at least as long as an event can still arrive', function (): void {
    // A resend with a corrected timestamp is caught only while the first
    // claim exists. If claims expired before the acceptance window closed, a
    // resend in the gap would be accepted and counted twice (ADR-0002).
    expect(config('metered.usage.deduplication.ttl_seconds'))
        ->toBeGreaterThanOrEqual(config('metered.usage.acceptance.max_age_seconds'));
});

it('sheds load well before the stream trims what it has not written', function (): void {
    // MAXLEN ~ trims the oldest entries whether or not they were written.
    // Backpressure has to stop the backlog long before it reaches the length
    // the stream is trimmed at, or trimming would discard accepted events
    // (ADR-0003).
    expect(config('metered.usage.stream.backpressure_threshold'))
        ->toBeLessThanOrEqual(intdiv((int) config('metered.usage.stream.max_length'), 2));
});
