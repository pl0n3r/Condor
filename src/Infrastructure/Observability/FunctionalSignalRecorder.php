<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Domain\Observability\Entity\FunctionalSignal;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Registra señales funcionales agregables (no auditoría de negocio por
 * tenant, que vive en App\Domain\Audit\Entity\AuditEvent). El contexto
 * nunca debe incluir PII: ni correos, ni nombres, ni IPs, ni contenido
 * de solicitudes. Un fallo de persistencia nunca debe romper el flujo
 * de negocio que originó la señal.
 */
final readonly class FunctionalSignalRecorder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, scalar> $context */
    public function record(
        string $type,
        ?string $tenantId = null,
        array $context = [],
    ): void {
        $signal = new FunctionalSignal($type, $tenantId, self::withoutLikelyPii($context));

        try {
            $this->entityManager->persist($signal);
            $this->entityManager->flush();
        } catch (Throwable $storageError) {
            error_log(sprintf(
                'Condor functional signal [%s/%s] no pudo persistirse (%s).',
                $type,
                $signal->id(),
                $storageError::class,
            ));
        }
    }

    /**
     * @param array<string, scalar> $context
     * @return array<string, scalar>
     */
    private static function withoutLikelyPii(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (is_string($value) && str_contains($value, '@')) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}
