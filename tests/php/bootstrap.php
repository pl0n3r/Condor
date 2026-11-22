<?php

declare(strict_types=1);

// Solo para PHPUnit: las previews sintéticas de RR. HH. exigen opt-out explícito.
// El producto conserva HR_REAL_DATA_ENABLED=true por defecto (#1940).
$hrFlag = 'HR_REAL_DATA_ENABLED';
if (
    getenv($hrFlag) === false
    && !array_key_exists($hrFlag, $_ENV)
    && !array_key_exists($hrFlag, $_SERVER)
) {
    putenv($hrFlag.'=false');
    $_ENV[$hrFlag] = 'false';
    $_SERVER[$hrFlag] = 'false';
}

require dirname(__DIR__, 2).'/config/bootstrap.php';
