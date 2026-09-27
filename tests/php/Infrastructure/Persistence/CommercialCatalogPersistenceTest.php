<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommercialCatalogPersistenceTest extends KernelTestCase
{
    public function testDoctrineMapsCommercialCatalogSeparately(): void
    {
        self::bootKernel();
        $schema = $this->generatedSchema();

        foreach ([
            'condor_commercial_plan',
            'condor_commercial_vertical',
            'condor_commercial_plan_version',
            'condor_commercial_plan_version_vertical',
        ] as $table) {
            self::assertTrue($schema->hasTable($table), $table);
        }
    }

    public function testSchemaContainsIdentityVersionAndTemporalConstraints(): void
    {
        self::bootKernel();
        $manager = $this->entityManager()->getConnection()->createSchemaManager();

        self::assertTrue(
            $manager->introspectTable('condor_commercial_plan')
                ->hasIndex('uniq_commercial_plan_key'),
        );
        self::assertTrue(
            $manager->introspectTable('condor_commercial_vertical')
                ->hasIndex('uniq_commercial_vertical_key'),
        );

        $version = $manager->introspectTable('condor_commercial_plan_version');
        self::assertTrue($version->hasIndex('uniq_commercial_plan_version'));
        self::assertTrue($version->hasIndex('idx_commercial_plan_effective'));
        $this->assertReferences($version, ['condor_commercial_plan']);

        $join = $manager->introspectTable(
            'condor_commercial_plan_version_vertical',
        );
        $this->assertReferences(
            $join,
            ['condor_commercial_plan_version', 'condor_commercial_vertical'],
        );
    }

    public function testGeneratedAndMigratedSchemaAlign(): void
    {
        self::bootKernel();
        $generated = $this->generatedSchema();
        $manager = $this->entityManager()->getConnection()->createSchemaManager();

        $expected = [
            'condor_commercial_plan' => ['id', 'catalog_key', 'name', 'active'],
            'condor_commercial_vertical' => ['id', 'catalog_key', 'name', 'active'],
            'condor_commercial_plan_version' => [
                'id', 'plan_id', 'version_number', 'currency',
                'monthly_amount', 'annual_amount', 'quote_required',
                'limits', 'effective_from', 'effective_until',
            ],
            'condor_commercial_plan_version_vertical' => [
                'plan_version_id', 'vertical_id',
            ],
        ];

        foreach ($expected as $name => $columns) {
            $this->assertColumns($generated->getTable($name), $columns);
            $this->assertColumns($manager->introspectTable($name), $columns);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function generatedSchema(): \Doctrine\DBAL\Schema\Schema
    {
        $manager = $this->entityManager();

        return (new SchemaTool($manager))->getSchemaFromMetadata(
            $manager->getMetadataFactory()->getAllMetadata(),
        );
    }

    /** @param list<string> $columns */
    private function assertColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            self::assertTrue($table->hasColumn($column), $column);
        }
    }

    /** @param list<string> $expected */
    private function assertReferences(Table $table, array $expected): void
    {
        $actual = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $actual[] = $foreignKey->getReferencedTableName()->toString();
        }
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }
}
