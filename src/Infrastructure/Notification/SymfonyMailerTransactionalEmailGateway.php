<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Application\Notification\TransactionalEmailGateway;
use App\Application\Notification\TransactionalEmailMessage;
use JsonException;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Throwable;

final readonly class SymfonyMailerTransactionalEmailGateway implements TransactionalEmailGateway
{
    private const CONFIGURATION_ERROR = 'El correo transaccional no está disponible.';
    private const DELIVERY_ERROR = 'No se pudo entregar el correo transaccional.';

    public function __construct(
        private MailerFactory $mailerFactory,
        private string $projectDir,
    ) {
    }

    public function isAvailable(): bool
    {
        $configuration = $this->configuration();

        return $configuration !== null
            && $this->providerIsDocumented($configuration['provider']);
    }

    public function deliver(TransactionalEmailMessage $message): void
    {
        $configuration = $this->configuration();
        if ($configuration === null || !$this->providerIsDocumented($configuration['provider'])) {
            throw new RuntimeException(self::CONFIGURATION_ERROR);
        }

        try {
            $email = $this->buildEmail($message, $configuration['from']);
            $this->mailerFactory->create($configuration['dsn'])->send($email);
        } catch (Throwable) {
            throw new RuntimeException(self::DELIVERY_ERROR);
        }
    }

    /** @return array{dsn: string, from: string, provider: string}|null */
    private function configuration(): ?array
    {
        $dsn = self::env('CONDOR_MAILER_DSN');
        $from = self::env('CONDOR_MAIL_FROM');
        $provider = self::env('CONDOR_MAIL_PROVIDER');

        if ($dsn === '' || $from === '' || $provider === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $provider) !== 1) {
            return null;
        }

        $scheme = parse_url($dsn, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['smtp', 'smtps'], true)) {
            return null;
        }

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return [
            'dsn' => $dsn,
            'from' => $from,
            'provider' => $provider,
        ];
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
                    .'El enlace vence en %d minutos. '
                    .'Si no solicitaste el cambio, ignora este mensaje.',
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
        $contents = @file_get_contents($this->projectDir.'/datos.yml');
        if (!is_string($contents) || $contents === '') {
            return false;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
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
        if (!is_string($value) || trim($value) === '') {
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
