<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogSchemaListenerTest extends KernelTestCase
{
    public function testGeneratedSchemaContainsTenantProductForeignKey(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $schema = (new SchemaTool($entityManager))->getSchemaFromMetadata(
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );
        $variantTable = $schema->getTable('condor_product_variant');

        self::assertTrue(
            $variantTable->hasForeignKey('FK_VARIANT_PRODUCT_TENANT'),
        );

        $foreignKey = $variantTable->getForeignKey(
            'FK_VARIANT_PRODUCT_TENANT',
        );

        self::assertSame(
            ['tenant_id', 'product_id'],
            array_map(
                static fn ($name): string => $name->toString(),
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
                static fn ($name): string => $name->toString(),
                $foreignKey->getReferencedColumnNames(),
            ),
        );
    }
}
