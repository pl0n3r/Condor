<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommercialCatalogSchemaTest extends KernelTestCase
{
    public function testGeneratedAndMigratedCommercialSchemaAlign(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $generated = (new SchemaTool($entityManager))->getSchemaFromMetadata(
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );
        $manager = $entityManager->getConnection()->createSchemaManager();

        $expected = [
            'condor_commercial_plan' => [
                'id', 'catalog_key', 'name', 'active', 'created_at',
            ],
            'condor_commercial_vertical' => [
                'id', 'catalog_key', 'name', 'active', 'created_at',
            ],
            'condor_commercial_plan_version' => [
                'id', 'plan_id', 'version_number', 'currency',
                'monthly_amount', 'annual_amount', 'quote_required',
                'limits', 'effective_from', 'effective_until', 'created_at',
            ],
            'condor_commercial_plan_version_vertical' => [
                'plan_version_id', 'vertical_id',
            ],
        ];

        foreach ($expected as $tableName => $columns) {
            self::assertTrue($generated->hasTable($tableName));
            $generatedTable = $generated->getTable($tableName);
            $migratedTable = $manager->introspectTable($tableName);
            $this->assertColumns($generatedTable, $columns);
            $this->assertColumns($migratedTable, $columns);
        }

        $generatedVersion = $generated->getTable(
            'condor_commercial_plan_version',
        );
        $migratedVersion = $manager->introspectTable(
            'condor_commercial_plan_version',
        );
        self::assertTrue(
            $generatedVersion->hasIndex('uniq_commercial_plan_version'),
        );
        self::assertTrue(
            $migratedVersion->hasIndex('uniq_commercial_plan_version'),
        );

        $this->assertReferencedTables(
            $migratedVersion,
            ['condor_commercial_plan'],
        );
        $this->assertReferencedTables(
            $manager->introspectTable(
                'condor_commercial_plan_version_vertical',
            ),
            [
                'condor_commercial_plan_version',
                'condor_commercial_vertical',
            ],
        );
    }

    /**
     * @param list<string> $columns
     */
    private function assertColumns(Table $table, array $columns): void
    {
        foreach ($columns as $column) {
            self::assertTrue(
                $table->hasColumn($column),
                sprintf('%s debe contener %s.', $table->getName(), $column),
            );
        }
    }

    /**
     * @param list<string> $expected
     */
    private function assertReferencedTables(
        Table $table,
        array $expected,
    ): void {
        $actual = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $actual[] = $foreignKey->getReferencedTableName()->toString();
        }
        sort($actual);
        sort($expected);

        self::assertSame($expected, $actual);
    }
}
