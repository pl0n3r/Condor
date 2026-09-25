<?php

declare(strict_types=1);

namespace App\Infrastructure\Runtime;

use Throwable;

/**
 * Detecta y repara en caliente el caso en que el contenedor compilado de
 * Symfony (var/cache/prod) quedó desincronizado con el código desplegado
 * (Issue #144): el despliegue por Git de Hostinger preserva esa carpeta
 * entre builds en vez de regenerarla, así que un cambio de servicios
 * produce un Error fatal originado dentro de los archivos compilados.
 *
 * Se usa desde public/index.php para reintentar la misma solicitud una
 * sola vez tras limpiar la caché, de forma transparente para quien la
 * hizo. El cron que ejecuta scripts/post-deploy.sh sigue existiendo como
 * respaldo (cubre el caso en que la primera solicitud tras el deploy no
 * la sirve un humano, por ejemplo un webhook o un bot).
 */
final class ContainerRecovery
{
    private const string RELATIVE_CACHE_DIR = 'var/cache/prod';
    private const string RELATIVE_LOCK_FILE = 'var/cache/.recovery.lock';

    public static function looksLikeStaleContainer(
        Throwable $error,
        string $projectDir,
    ): bool {
        if (!$error instanceof \Error) {
            return false;
        }

        $prefix = self::cacheDir($projectDir).DIRECTORY_SEPARATOR;

        return str_starts_with($error->getFile(), $prefix);
    }

    /**
     * Limpia var/cache/prod de forma exclusiva (flock no bloqueante): si
     * otra solicitud concurrente ya está reparando, esta simplemente no
     * reintenta y cae al error seguro habitual en vez de competir por los
     * mismos archivos.
     */
    public static function clearProdCache(string $projectDir): bool
    {
        $dir = self::cacheDir($projectDir);
        if (!is_dir($dir)) {
            return true;
        }

        $lockPath = rtrim($projectDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.self::RELATIVE_LOCK_FILE;
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            return false;
        }

        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            return self::removeContents($dir);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function removeContents(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }

        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && !is_link($path)) {
                $ok = (self::removeContents($path) && @rmdir($path)) && $ok;
            } else {
                $ok = @unlink($path) && $ok;
            }
        }

        return $ok;
    }

    private static function cacheDir(string $projectDir): string
    {
        return rtrim($projectDir, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_CACHE_DIR);
    }
}
