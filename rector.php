<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src/Infrastructure/Persistence/CatalogSchemaListener.php',
        __DIR__ . '/src/Infrastructure/Runtime/ContainerRecovery.php',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withComposerBased(
        doctrine: true,
        symfony: true,
    );
