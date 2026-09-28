<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use RuntimeException;

final readonly class E2eFileTransactionalEmailGateway implements TransactionalEmailGateway
{
    public function __construct(
        private string $environment,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->environment === 'test' && $this->mailboxPath() !== null;
    }

    public function deliver(TransactionalEmailMessage $message): void
    {
        $path = $this->mailboxPath();
        if (!$this->isAvailable() || $path === null) {
            throw new RuntimeException('El mailbox transaccional E2E no está disponible.');
        }

        $payload = json_encode(
            [
                'recipient' => $message->recipient,
                'templateKey' => $message->templateKey,
                'templateData' => $message->templateData,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $handle = $this->openMailbox($path);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el mailbox transaccional E2E.');
            }

            $this->assertSecureHandle($handle, $path);

            if (fwrite($handle, $payload.PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException('No se pudo escribir el mailbox transaccional E2E.');
            }
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource */
    private function openMailbox(string $path)
    {
        $previousUmask = umask(0077);

        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }

        if ($handle !== false) {
            if (!@chmod($path, 0600)) {
                fclose($handle);
                @unlink($path);

                throw new RuntimeException('No se pudo proteger el mailbox transaccional E2E.');
            }

            return $handle;
        }

        $stat = @lstat($path);
        if (!$this->isSecureOwnedRegularFile($stat)) {
            throw new RuntimeException('El mailbox transaccional E2E existente no es seguro.');
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el mailbox transaccional E2E.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function assertSecureHandle($handle, string $path): void
    {
        $handleStat = @fstat($handle);
        $pathStat = @lstat($path);

        if (
            !$this->isSecureOwnedRegularFile($handleStat)
            || !$this->isSecureOwnedRegularFile($pathStat)
            || $handleStat['dev'] !== $pathStat['dev']
            || $handleStat['ino'] !== $pathStat['ino']
        ) {
            throw new RuntimeException('El mailbox transaccional E2E cambió o no es seguro.');
        }
    }

    /** @param array<string|int, int>|false $stat */
    private function isSecureOwnedRegularFile(array|false $stat): bool
    {
        if ($stat === false) {
            return false;
        }

        $uid = function_exists('posix_geteuid')
            ? posix_geteuid()
            : getmyuid();

        return is_int($uid)
            && ($stat['mode'] & 0170000) === 0100000
            && ($stat['mode'] & 0777) === 0600
            && $stat['uid'] === $uid;
    }

    private function mailboxPath(): ?string
    {
        $raw = $_SERVER['CONDOR_E2E_MAILBOX_PATH']
            ?? $_ENV['CONDOR_E2E_MAILBOX_PATH']
            ?? getenv('CONDOR_E2E_MAILBOX_PATH');

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $path = trim($raw);
        $tmp = realpath(sys_get_temp_dir());
        $directory = realpath(dirname($path));

        if (
            !is_string($tmp)
            || !is_string($directory)
            || $directory !== $tmp
            || is_link($path)
        ) {
            return null;
        }

        return $path;
    }
}
