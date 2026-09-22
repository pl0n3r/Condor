<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogSchemaListenerTest extends KernelTestCase
{
    public function testGeneratedAndMigratedSchemaContainTenantProductConstraint(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $generatedSchema = (new SchemaTool($entityManager))->getSchemaFromMetadata(
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );
        $this->assertTenantProductConstraint(
            $generatedSchema->getTable('condor_product_variant'),
        );

        $migratedTable = $entityManager
            ->getConnection()
            ->createSchemaManager()
            ->introspectTable('condor_product_variant');
        $this->assertTenantProductConstraint($migratedTable);
    }

    private function assertTenantProductConstraint(Table $variantTable): void
    {
        self::assertTrue(
            $variantTable->hasIndex('IDX_VARIANT_TENANT_PRODUCT'),
        );
        self::assertTrue(
            $variantTable->hasForeignKey('FK_VARIANT_PRODUCT_TENANT'),
        );

        $foreignKey = $variantTable->getForeignKey(
            'FK_VARIANT_PRODUCT_TENANT',
        );

        self::assertSame(
            ['tenant_id', 'product_id'],
            array_map(
                static fn (UnqualifiedName $name): string => $name->toString(),
                $foreignKey->getReferencingColumnNames(),
            ),
        );
        self::assertSame(
            'condor_product',
            $foreignKey->getReferencedTableName()->toString(),
        );
        self::assertSame(
            ['tenant_id', 'id'],
            array_map(
                static fn (UnqualifiedName $name): string => $name->toString(),
                $foreignKey->getReferencedColumnNames(),
            ),
        );
    }
}
