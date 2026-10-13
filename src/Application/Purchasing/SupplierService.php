<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Purchasing\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

final readonly class SupplierService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function create(Tenant $tenant, string $code, string $name, int $paymentTermsDays = 0): Supplier
    {
        $code = Supplier::canonicalCode($code);
        $this->assertCodeAvailable($tenant, $code);
        $supplier = new Supplier($tenant, $code, $name, $paymentTermsDays);
        $this->entityManager->persist($supplier);

        return $supplier;
    }

    public function update(
        Tenant $tenant,
        Supplier $supplier,
        string $code,
        string $name,
        int $paymentTermsDays,
    ): Supplier {
        self::assertOwnedSupplier($tenant, $supplier);
        $code = Supplier::canonicalCode($code);
        $this->assertCodeAvailable($tenant, $code, $supplier);
        $supplier->update($code, $name, $paymentTermsDays);

        return $supplier;
    }

    public function deactivate(Tenant $tenant, Supplier $supplier): Supplier
    {
        self::assertOwnedSupplier($tenant, $supplier);
        $supplier->deactivate();

        return $supplier;
    }

    private function assertCodeAvailable(Tenant $tenant, string $code, ?Supplier $current = null): void
    {
        // Los persist() pendientes no aparecen en findOneBy() antes de flush().
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if (
                $pending instanceof Supplier
                && $pending->tenant()->id() === $tenant->id()
                && $pending->code() === $code
                && ($current === null || $pending->id() !== $current->id())
            ) {
                throw new DomainException('Ya existe un proveedor con ese código en el tenant.');
            }
        }

        $existing = $this->entityManager->getRepository(Supplier::class)->findOneBy([
            'tenant' => $tenant,
            'code' => $code,
        ]);
        if (
            $existing instanceof Supplier
            && (!$current instanceof Supplier || $existing->id() !== $current->id())
        ) {
            throw new DomainException('Ya existe un proveedor con ese código en el tenant.');
        }
    }

    private static function assertOwnedSupplier(Tenant $tenant, Supplier $supplier): void
    {
        if ($supplier->tenant()->id() !== $tenant->id()) {
            throw new DomainException('El proveedor pertenece a otro tenant.');
        }
    }
}
