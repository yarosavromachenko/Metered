<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/database',
        __DIR__ . '/config',
    ])
    ->withSkip([
        __DIR__ . '/bootstrap/cache',
        __DIR__ . '/storage',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
    ])
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets(php84: true);
