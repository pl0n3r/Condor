<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Catalog\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class CatalogSchemaListener
{
    private const string TENANT_PRODUCT_FOREIGN_KEY = 'FK_VARIANT_PRODUCT_TENANT';
    private const string TENANT_PRODUCT_INDEX = 'IDX_VARIANT_TENANT_PRODUCT';

    public function postGenerateSchemaTable(
        GenerateSchemaTableEventArgs $event,
    ): void {
        if ($event->getClassMetadata()->getName() !== ProductVariant::class) {
            return;
        }

        $table = $event->getClassTable();
        if (!$table->hasIndex(self::TENANT_PRODUCT_INDEX)) {
            $table->addIndex(
                ['tenant_id', 'product_id'],
                self::TENANT_PRODUCT_INDEX,
            );
        }

        if ($table->hasForeignKey(self::TENANT_PRODUCT_FOREIGN_KEY)) {
            return;
        }

        $table->addForeignKeyConstraint(
            'condor_product',
            ['tenant_id', 'product_id'],
            ['tenant_id', 'id'],
            ['onDelete' => 'CASCADE'],
            self::TENANT_PRODUCT_FOREIGN_KEY,
        );
    }
}
