<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Historical catalog-v3 migration marker.
 *
 * D-054 permits only allowlisted expand-compatible SQL before the backup gate.
 * Commercial prices and version history are data, not schema: the existing
 * transactional CommercialCatalogSeeder runs after schema convergence and
 * creates v1/v2/v3 with the same prices and inherited relationships.
 * This marker must not emit SQL, including on installations which had v2.
 */
final class Version20261106140000 extends AbstractMigration // NOSONAR -- Doctrine convention
{
    public function getDescription(): string
    {
        return 'Catálogo comercial v3: versión histórica; materialización idempotente en el seeder posterior al esquema.';
    }

    public function up(Schema $schema): void
    {
        // Intentionally empty: Doctrine records this historical version;
        // app:commercial:seed handles the catalog after the D-054 backup gate.
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Las tarifas v3 y su historial se conservan; no revertir datos comerciales.',
        );
    }
}
