<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Reserva atómica: toda alta facturable corre en una transacción que toma
 * lock de escritura sobre la fila del tenant, de modo que dos altas
 * concurrentes se serializan y no pueden exceder el límite.
 */
final readonly class TenantUsageReservation
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     * @param callable(Tenant):T $write
     * @return T
     */
    public function run(ExecutionContext $context, callable $write): mixed
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($context, $write): mixed {
                $tenant = $this->entityManager->find(
                    Tenant::class,
                    $context->tenantId,
                    LockMode::PESSIMISTIC_WRITE,
                );
                if (!$tenant instanceof Tenant) {
                    throw new DomainException('Tenant inexistente.');
                }

                return $write($tenant);
            },
        );
    }
}
