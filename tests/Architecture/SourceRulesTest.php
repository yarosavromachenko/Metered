<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Two rules that are about the text of the code rather than its dependency
 * graph, and are therefore checked by reading the source.
 *
 * Both exist because they are the rules most likely to be broken by a quick
 * fix that looks harmless in review.
 */

/**
 * @return list<array{path: string, code: string}>
 */
function sourceFilesIn(string $relativePath): array
{
    $absolute = dirname(__DIR__, 2) . '/' . $relativePath;

    if (! is_dir($absolute)) {
        return [];
    }

    $files = [];

    foreach (Finder::create()->files()->in($absolute)->name('*.php') as $file) {
        $files[] = [
            'path' => $file->getRelativePathname(),
            'code' => $file->getContents(),
        ];
    }

    return $files;
}

it('never types money or quantities as float', function (): void {
    $offenders = [];

    foreach (['Domain', 'Application'] as $layer) {
        foreach (['Shared', 'Tenancy', 'Usage', 'Billing', 'Invoicing', 'Webhooks'] as $module) {
            foreach (sourceFilesIn("src/{$module}/{$layer}") as $file) {
                // Matches `float $x`, `: float`, `?float`, and iterable<float>.
                if (preg_match('/(?<![\w$])\??float(?![\w])/', $file['code']) === 1) {
                    $offenders[] = "{$module}/{$layer}/{$file['path']}";
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Money is Money and quantities are BigDecimal. A float in a billing '
        . 'calculation is a rounding error waiting for a customer to find it.',
    );
});

it('never writes to the database from a presentation class', function (): void {
    $offenders = [];

    foreach (['Shared', 'Tenancy', 'Usage', 'Billing', 'Invoicing', 'Webhooks', 'Admin'] as $module) {
        foreach (sourceFilesIn("src/{$module}/Presentation") as $file) {
            $writes = '/->(save|update|delete|forceDelete|increment|decrement|restore)\s*\(|::(create|updateOrCreate|firstOrCreate|insert|upsert|destroy)\s*\(/';

            if (preg_match($writes, $file['code']) === 1) {
                $offenders[] = "{$module}/Presentation/{$file['path']}";
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Presentation reads; it does not write. Finalizing an invoice writes '
        . 'the invoice, the ledger entries and the outbox message in one '
        . 'transaction — a form save would produce none of that.',
    );
});
