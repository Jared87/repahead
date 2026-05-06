<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/public',
    ])
    ->withPhpSets(php82: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withSkip([
        // Removes "unused" request params from route handlers. The PSR-15-shaped
        // signature is part of our public contract — keep it.
        RemoveUnusedPublicMethodParameterRector::class,
        // Collapses readable multi-line closures into one-line arrow functions.
        // We prefer the multi-line form for nested rebuild closures.
        ClosureToArrowFunctionRector::class,
        ClosureReturnTypeRector::class,
    ])
    ->withCache(cacheDirectory: __DIR__ . '/.phpunit.cache/rector');
