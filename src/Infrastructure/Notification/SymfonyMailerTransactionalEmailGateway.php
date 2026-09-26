<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use RuntimeException;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailerTransactionalEmailGateway implements TransactionalEmailGateway
{
    public function __construct(
        private MailerFactory $mailerFactory,
        private string $projectDir,
    ) {
    }

    public function deliver(TransactionalEmailMessage $message): void
    {
        $dsn = self::env('CONDOR_MAILER_DSN');
        $from = self::env('CONDOR_MAIL_FROM');
        $provider = self::env('CONDOR_MAIL_PROVIDER');

        if ($dsn === '' || $from === '' || $provider === '') {
            throw new RuntimeException('El correo transaccional no está configurado.');
        }

        $scheme = strtolower((string) parse_url($dsn, PHP_URL_SCHEME));
        if (!in_array($scheme, ['smtp', 'smtps'], true)) {
            throw new RuntimeException('Solo se permiten transportes SMTP/SMTPS.');
        }

        if (!$this->providerIsDocumented($provider)) {
            throw new RuntimeException('El proveedor SMTP no está documentado en datos.yml.');
        }

        $this->mailerFactory->create($dsn)->send(
            $this->buildEmail($message, $from),
        );
    }

    private function buildEmail(TransactionalEmailMessage $message, string $from): Email
    {
        return match ($message->templateKey) {
            'account_password_reset' => (new Email())
                ->from($from)
                ->to($message->recipient)
                ->subject('Restablece tu contraseña de Condor')
                ->text(sprintf(
                    "Abre este enlace para restablecer tu contraseña:\n%s\n\n"
                    ."El enlace vence en %d minutos. "
                    ."Si no solicitaste el cambio, ignora este mensaje.",
                    self::requiredString($message, 'reset_url'),
                    self::requiredInt($message, 'expires_in_minutes'),
                )),
            'account_password_changed' => (new Email())
                ->from($from)
                ->to($message->recipient)
                ->subject('Tu contraseña de Condor cambió')
                ->text(
                    'La contraseña de tu cuenta de Condor fue actualizada. '
                    .'Si no reconoces este cambio, contacta al administrador.',
                ),
            default => throw new RuntimeException('Plantilla de correo transaccional no soportada.'),
        };
    }

    private function providerIsDocumented(string $provider): bool
    {
        $path = $this->projectDir.'/datos.yml';
        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return false;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!is_array($decoded) || !isset($decoded['treatments']) || !is_array($decoded['treatments'])) {
            return false;
        }

        foreach ($decoded['treatments'] as $treatment) {
            if (!is_array($treatment) || ($treatment['id'] ?? null) !== 'condor_password_reset') {
                continue;
            }

            $providers = $treatment['providers'] ?? [];

            return is_array($providers) && in_array($provider, $providers, true);
        }

        return false;
    }

    private static function requiredString(TransactionalEmailMessage $message, string $key): string
    {
        $value = $message->templateData[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Falta el dato requerido %s.', $key));
        }

        return $value;
    }

    private static function requiredInt(TransactionalEmailMessage $message, string $key): int
    {
        $value = $message->templateData[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException(sprintf('Falta el dato requerido %s.', $key));
        }

        return $value;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
