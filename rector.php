<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

// Check only in CI (`composer rector`); apply locally with `vendor/bin/rector`.
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets(php82: true)
    ->withSets([LaravelSetList::LARAVEL_120])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
