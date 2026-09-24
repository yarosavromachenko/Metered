<?php

declare(strict_types=1);

/*
 * The mechanical half of docs/adr/0018-design-principles.md. The other half —
 * whether an abstraction has a present-day need, whether a pattern is the right
 * one — is judgement, and lives in .github/PULL_REQUEST_TEMPLATE.md instead.
 *
 * These are written with reflection rather than Pest's arch() because each one
 * needs an exemption that a namespace expectation cannot express (an abstract
 * class, the Throwable ladder), and because a failure should name every
 * offender at once instead of the first one found.
 */

/** The widest port in the codebase today declares four methods. */
const MAX_INTERFACE_METHODS = 5;

/** The single entry point a handler is allowed to expose. */
const HANDLER_ENTRY_POINTS = ['handle', '__invoke'];

/**
 * Every symbol declared under src/, reflected.
 *
 * @return list<ReflectionClass<object>>
 */
function sourceSymbols(): array
{
    /** @var list<ReflectionClass<object>>|null $symbols */
    static $symbols = null;

    if ($symbols !== null) {
        return $symbols;
    }

    $root = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $found = [];

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // PSR-4: Metered\ maps to src/, so the path is the name.
        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $name = 'Metered\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name)) {
            $found[$name] = new ReflectionClass($name);
        }
    }

    ksort($found);

    return $symbols = array_values($found);
}

it('closes every class that is not an extension point', function (): void {
    // Interfaces, enums and abstract classes are the extension points. Anything
    // else is final, so behaviour is added by composition or by a new class
    // rather than by subclassing something that never offered to be a base.
    $open = array_map(
        static fn(ReflectionClass $symbol): string => $symbol->getName(),
        array_filter(sourceSymbols(), static fn(ReflectionClass $symbol): bool => ! $symbol->isInterface()
            && ! $symbol->isEnum()
            && ! $symbol->isTrait()
            && ! $symbol->isAbstract()
            && ! $symbol->isFinal()),
    );

    expect(array_values($open))->toBe([]);
});

it('builds the domain on abstractions rather than on concrete parents', function (): void {
    $inherited = [];

    foreach (sourceSymbols() as $symbol) {
        if (! str_contains($symbol->getName(), '\\Domain\\')) {
            continue;
        }

        $parent = $symbol->getParentClass();

        // PHP's own Throwable hierarchy is concrete the whole way down, so a
        // domain exception has no abstract parent available to it.
        if ($parent === false || $symbol->implementsInterface(Throwable::class) || $parent->isAbstract()) {
            continue;
        }

        $inherited[] = $symbol->getName() . ' extends ' . $parent->getName();
    }

    expect($inherited)->toBe([]);
});

it('keeps repository interfaces in the domain and their implementations in the infrastructure', function (): void {
    $misplaced = [];

    foreach (sourceSymbols() as $symbol) {
        if (! str_ends_with($symbol->getShortName(), 'Repository')) {
            continue;
        }

        $name = $symbol->getName();
        $layer = $symbol->isInterface() ? 'Domain' : 'Infrastructure';

        if (! str_contains($name, '\\' . $layer . '\\')) {
            $misplaced[] = $symbol->isInterface()
                ? $name . ' is a repository port declared outside Domain'
                : $name . ' is a repository implementation outside Infrastructure';
        }
    }

    expect($misplaced)->toBe([]);
});

it('keeps interfaces small enough to be one role', function (): void {
    $wide = [];

    foreach (sourceSymbols() as $symbol) {
        if (! $symbol->isInterface()) {
            continue;
        }

        $methods = count($symbol->getMethods());

        if ($methods > MAX_INTERFACE_METHODS) {
            $wide[] = $symbol->getName() . ' declares ' . $methods . ' methods';
        }
    }

    expect($wide)->toBe([]);
});

it('gives every handler exactly one way in', function (): void {
    // One use case, one entry point. Also empty today, and for the same reason
    // as the repository rule above.
    $offenders = [];

    foreach (sourceSymbols() as $symbol) {
        if ($symbol->isInterface() || ! str_ends_with($symbol->getShortName(), 'Handler')) {
            continue;
        }

        $entryPoints = array_values(array_filter(
            array_map(
                static fn(ReflectionMethod $method): string => $method->getName(),
                $symbol->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
            static fn(string $method): bool => ! str_starts_with($method, '__') || $method === '__invoke',
        ));

        $named = array_values(array_intersect($entryPoints, HANDLER_ENTRY_POINTS));

        if (count($named) !== 1 || count($entryPoints) !== 1) {
            $offenders[] = $symbol->getName() . ' exposes [' . implode(', ', $entryPoints) . ']';
        }
    }

    expect($offenders)->toBe([]);
});
