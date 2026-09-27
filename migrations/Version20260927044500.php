<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927044500 extends AbstractMigration // NOSONAR -- nombre requerido por Doctrine Migrations
{
    public function getDescription(): string
    {
        return 'Commercial Catalog V1: planes versionados, verticales, capabilities y add-ons.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_plan (
    id VARCHAR(26) NOT NULL,
    catalog_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_commercial_plan_key (catalog_key),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_vertical (
    id VARCHAR(26) NOT NULL,
    catalog_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_commercial_vertical_key (catalog_key),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_capability (
    id VARCHAR(26) NOT NULL,
    catalog_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    active TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_commercial_capability_key (catalog_key),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_addon (
    id VARCHAR(26) NOT NULL,
    catalog_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    active TINYINT(1) DEFAULT 1 NOT NULL,
    pricing_mode VARCHAR(16) NOT NULL,
    monthly_price_cop BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    UNIQUE INDEX uniq_commercial_addon_key (catalog_key),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_plan_version (
    id VARCHAR(26) NOT NULL,
    plan_id VARCHAR(26) NOT NULL,
    version_number INT NOT NULL,
    pricing_mode VARCHAR(16) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    monthly_price_cop BIGINT DEFAULT NULL,
    annual_price_cop BIGINT DEFAULT NULL,
    limits JSON NOT NULL,
    valid_from DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    valid_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
    available_for_sale TINYINT(1) DEFAULT 1 NOT NULL,
    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    INDEX idx_commercial_plan_version_plan (plan_id),
    INDEX idx_commercial_plan_version_validity (valid_from, valid_until),
    UNIQUE INDEX uniq_commercial_plan_version (plan_id, version_number),
    PRIMARY KEY(id),
    CONSTRAINT fk_commercial_plan_version_plan
        FOREIGN KEY (plan_id) REFERENCES condor_commercial_plan (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_plan_version_vertical (
    plan_version_id VARCHAR(26) NOT NULL,
    vertical_id VARCHAR(26) NOT NULL,
    INDEX idx_plan_version_vertical_vertical (vertical_id),
    PRIMARY KEY(plan_version_id, vertical_id),
    CONSTRAINT fk_plan_version_vertical_version
        FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_version_vertical_vertical
        FOREIGN KEY (vertical_id) REFERENCES condor_commercial_vertical (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_plan_version_capability (
    plan_version_id VARCHAR(26) NOT NULL,
    capability_id VARCHAR(26) NOT NULL,
    INDEX idx_plan_version_capability_capability (capability_id),
    PRIMARY KEY(plan_version_id, capability_id),
    CONSTRAINT fk_plan_version_capability_version
        FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_version_capability_capability
        FOREIGN KEY (capability_id) REFERENCES condor_commercial_capability (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE condor_commercial_plan_version_addon (
    plan_version_id VARCHAR(26) NOT NULL,
    addon_id VARCHAR(26) NOT NULL,
    INDEX idx_plan_version_addon_addon (addon_id),
    PRIMARY KEY(plan_version_id, addon_id),
    CONSTRAINT fk_plan_version_addon_version
        FOREIGN KEY (plan_version_id) REFERENCES condor_commercial_plan_version (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_version_addon_addon
        FOREIGN KEY (addon_id) REFERENCES condor_commercial_addon (id) ON DELETE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);

        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_plan (id, catalog_key, name, active, created_at, updated_at) VALUES
('01K6A000000000000000000001', 'basic', 'Básico', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6A000000000000000000002', 'business', 'Negocio', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6A000000000000000000003', 'pro', 'Pro', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6A000000000000000000004', 'enterprise', 'Enterprise', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_vertical (id, catalog_key, name, active, created_at, updated_at) VALUES
('01K6B000000000000000000001', 'commerce-distribution-ecommerce', 'Comercio / distribución / ecommerce', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6B000000000000000000002', 'textile-fashion', 'Textil / moda / confección', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6B000000000000000000003', 'light-manufacturing', 'Manufactura ligera', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6B000000000000000000004', 'professional-services', 'Servicios profesionales', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6B000000000000000000005', 'legal', 'Legal / abogados', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_capability (id, catalog_key, name, active, created_at, updated_at) VALUES
('01K6C000000000000000000001', 'catalog.core', 'Catálogo', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000002', 'inventory.core', 'Inventario', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000003', 'orders.core', 'Pedidos y ventas', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000004', 'transfers', 'Transferencias', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000005', 'custom-domain', 'Dominio propio', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000006', 'wholesale-pricing', 'Mayoristas y listas de precios', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000007', 'reporting.operational', 'Reportes operativos', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000008', 'production.full', 'Producción completa', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000009', 'multi-company', 'Multiempresa', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000010', 'api-webhooks', 'API y webhooks', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6C000000000000000000011', 'analytics.advanced', 'Analítica avanzada', 1, '2026-09-27 00:00:00', '2026-09-27 00:00:00')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_addon (id, catalog_key, name, active, pricing_mode, monthly_price_cop, created_at, updated_at) VALUES
('01K6D000000000000000000001', 'extra-user', 'Usuario adicional', 1, 'fixed', 14900, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6D000000000000000000002', 'extra-site', 'Sede adicional', 1, 'fixed', 29900, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6D000000000000000000003', 'extra-company', 'Empresa adicional', 1, 'fixed', 79900, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6D000000000000000000004', 'extra-store-brand', 'Tienda / marca adicional', 1, 'fixed', 49900, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6D000000000000000000005', 'production-lite', 'Producción Lite', 1, 'fixed', 99900, '2026-09-27 00:00:00', '2026-09-27 00:00:00'),
('01K6D000000000000000000006', 'premium-integration', 'Integración premium', 1, 'from', 49900, '2026-09-27 00:00:00', '2026-09-27 00:00:00')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_plan_version (id, plan_id, version_number, pricing_mode, currency, monthly_price_cop, annual_price_cop, limits, valid_from, valid_until, available_for_sale, created_at) VALUES
('01K6E000000000000000000001', '01K6A000000000000000000001', 1, 'fixed', 'COP', 79900, 799000, '{"companies":1,"users":3,"sites":1}', '2026-09-27 00:00:00', NULL, 1, '2026-09-27 00:00:00'),
('01K6E000000000000000000002', '01K6A000000000000000000002', 1, 'fixed', 'COP', 199900, 1999000, '{"companies":1,"users":10,"sites":3}', '2026-09-27 00:00:00', NULL, 1, '2026-09-27 00:00:00'),
('01K6E000000000000000000003', '01K6A000000000000000000003', 1, 'fixed', 'COP', 499900, 4999000, '{"companies":5,"users":30,"sites":10}', '2026-09-27 00:00:00', NULL, 1, '2026-09-27 00:00:00'),
('01K6E000000000000000000004', '01K6A000000000000000000004', 1, 'from', 'COP', 1200000, NULL, '{"companies":null,"users":null,"sites":null}', '2026-09-27 00:00:00', NULL, 1, '2026-09-27 00:00:00')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_plan_version_vertical (plan_version_id, vertical_id) VALUES
('01K6E000000000000000000001', '01K6B000000000000000000001'),
('01K6E000000000000000000001', '01K6B000000000000000000002'),
('01K6E000000000000000000001', '01K6B000000000000000000003'),
('01K6E000000000000000000001', '01K6B000000000000000000004'),
('01K6E000000000000000000001', '01K6B000000000000000000005'),
('01K6E000000000000000000002', '01K6B000000000000000000001'),
('01K6E000000000000000000002', '01K6B000000000000000000002'),
('01K6E000000000000000000002', '01K6B000000000000000000003'),
('01K6E000000000000000000002', '01K6B000000000000000000004'),
('01K6E000000000000000000002', '01K6B000000000000000000005'),
('01K6E000000000000000000003', '01K6B000000000000000000001'),
('01K6E000000000000000000003', '01K6B000000000000000000002'),
('01K6E000000000000000000003', '01K6B000000000000000000003'),
('01K6E000000000000000000003', '01K6B000000000000000000004'),
('01K6E000000000000000000003', '01K6B000000000000000000005'),
('01K6E000000000000000000004', '01K6B000000000000000000001'),
('01K6E000000000000000000004', '01K6B000000000000000000002'),
('01K6E000000000000000000004', '01K6B000000000000000000003'),
('01K6E000000000000000000004', '01K6B000000000000000000004'),
('01K6E000000000000000000004', '01K6B000000000000000000005')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_plan_version_capability (plan_version_id, capability_id) VALUES
('01K6E000000000000000000001', '01K6C000000000000000000001'),
('01K6E000000000000000000001', '01K6C000000000000000000002'),
('01K6E000000000000000000001', '01K6C000000000000000000003'),
('01K6E000000000000000000002', '01K6C000000000000000000001'),
('01K6E000000000000000000002', '01K6C000000000000000000002'),
('01K6E000000000000000000002', '01K6C000000000000000000003'),
('01K6E000000000000000000002', '01K6C000000000000000000004'),
('01K6E000000000000000000002', '01K6C000000000000000000005'),
('01K6E000000000000000000002', '01K6C000000000000000000006'),
('01K6E000000000000000000002', '01K6C000000000000000000007'),
('01K6E000000000000000000003', '01K6C000000000000000000001'),
('01K6E000000000000000000003', '01K6C000000000000000000002'),
('01K6E000000000000000000003', '01K6C000000000000000000003'),
('01K6E000000000000000000003', '01K6C000000000000000000004'),
('01K6E000000000000000000003', '01K6C000000000000000000005'),
('01K6E000000000000000000003', '01K6C000000000000000000006'),
('01K6E000000000000000000003', '01K6C000000000000000000007'),
('01K6E000000000000000000003', '01K6C000000000000000000008'),
('01K6E000000000000000000003', '01K6C000000000000000000009'),
('01K6E000000000000000000003', '01K6C000000000000000000010'),
('01K6E000000000000000000003', '01K6C000000000000000000011'),
('01K6E000000000000000000004', '01K6C000000000000000000001'),
('01K6E000000000000000000004', '01K6C000000000000000000002'),
('01K6E000000000000000000004', '01K6C000000000000000000003'),
('01K6E000000000000000000004', '01K6C000000000000000000004'),
('01K6E000000000000000000004', '01K6C000000000000000000005'),
('01K6E000000000000000000004', '01K6C000000000000000000006'),
('01K6E000000000000000000004', '01K6C000000000000000000007'),
('01K6E000000000000000000004', '01K6C000000000000000000008'),
('01K6E000000000000000000004', '01K6C000000000000000000009'),
('01K6E000000000000000000004', '01K6C000000000000000000010'),
('01K6E000000000000000000004', '01K6C000000000000000000011')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO condor_commercial_plan_version_addon (plan_version_id, addon_id) VALUES
('01K6E000000000000000000001', '01K6D000000000000000000001'),
('01K6E000000000000000000001', '01K6D000000000000000000002'),
('01K6E000000000000000000002', '01K6D000000000000000000001'),
('01K6E000000000000000000002', '01K6D000000000000000000002'),
('01K6E000000000000000000002', '01K6D000000000000000000004'),
('01K6E000000000000000000002', '01K6D000000000000000000005'),
('01K6E000000000000000000002', '01K6D000000000000000000006'),
('01K6E000000000000000000003', '01K6D000000000000000000001'),
('01K6E000000000000000000003', '01K6D000000000000000000002'),
('01K6E000000000000000000003', '01K6D000000000000000000003'),
('01K6E000000000000000000003', '01K6D000000000000000000004'),
('01K6E000000000000000000003', '01K6D000000000000000000006'),
('01K6E000000000000000000004', '01K6D000000000000000000001'),
('01K6E000000000000000000004', '01K6D000000000000000000002'),
('01K6E000000000000000000004', '01K6D000000000000000000003'),
('01K6E000000000000000000004', '01K6D000000000000000000004'),
('01K6E000000000000000000004', '01K6D000000000000000000006')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE condor_commercial_plan_version_addon');
        $this->addSql('DROP TABLE condor_commercial_plan_version_capability');
        $this->addSql('DROP TABLE condor_commercial_plan_version_vertical');
        $this->addSql('DROP TABLE condor_commercial_plan_version');
        $this->addSql('DROP TABLE condor_commercial_addon');
        $this->addSql('DROP TABLE condor_commercial_capability');
        $this->addSql('DROP TABLE condor_commercial_vertical');
        $this->addSql('DROP TABLE condor_commercial_plan');
    }
}
