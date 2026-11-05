<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Organization\Entity\Branch;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Métrica facturable (GAP-P0-04, ver docs/architecture/capability-registry.md).
 *
 * - Asiento: membresía activa del tenant cuyo rol no es OWNER.
 * - Sede: sede del tenant que no es la sede inicial (default) del onboarding.
 *
 * Se calcula desde las tablas de negocio; no hay contador persistido que
 * pueda divergir. Para reservar, el llamador debe sostener el lock del tenant.
 */
final readonly class BillableUsage
{
    public const SEATS = 'users';
    public const LOCATIONS = 'locations';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function seats(string $tenantId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(m.id) FROM '.Membership::class.' m
             WHERE IDENTITY(m.tenant) = :tenant AND m.active = true AND m.roleKey <> :owner',
        )
            ->setParameter('tenant', $tenantId)
            ->setParameter('owner', Membership::ROLE_OWNER)
            ->getSingleScalarResult();
    }

    public function locations(string $tenantId): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(b.id) FROM '.Branch::class.' b
             WHERE IDENTITY(b.tenant) = :tenant AND b.default = false',
        )
            ->setParameter('tenant', $tenantId)
            ->getSingleScalarResult();
    }

    /**
     * Rechaza si añadir una unidad supera el límite del snapshot. Un límite
     * null significa «sin límite definido»: no se inventa ninguno.
     */
    public function assertCanAdd(ExecutionContext $context, string $metric, int $current): void
    {
        $limit = $context->snapshot->limit($metric);
        if ($limit === null) {
            return;
        }
        if (!is_int($limit) || $limit < 0) {
            throw new DomainException('Límite comercial inválido para '.$metric.'.');
        }
        if ($current >= $limit) {
            throw new UsageLimitExceeded($metric, $limit);
        }
    }
}
