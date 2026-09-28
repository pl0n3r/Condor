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

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el mailbox transaccional E2E.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el mailbox transaccional E2E.');
            }

            if (fwrite($handle, $payload.PHP_EOL) === false || !fflush($handle)) {
                throw new RuntimeException('No se pudo escribir el mailbox transaccional E2E.');
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);
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
