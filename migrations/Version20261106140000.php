<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * #1684: tarifa v3 del catálogo. Solo DML versionado; nunca ejecuta pagos.
 *
 * Las operaciones se encolan con addSql para respetar --dry-run y D-054.
 * El seed posterior cubre instalaciones que aún no tienen planes al migrar.
 */
final class Version20261106140000 extends AbstractMigration // NOSONAR -- nombre canónico Doctrine
{
    /** @var array<string,array{?int,?int,bool}> */
    private const PRICES = [
        'basic' => [79900, 799000, false],
        'business' => [199900, 1999000, false],
        'pro' => [499900, 4999000, false],
        'enterprise' => [null, null, true],
    ];

    /** @var array<string,string> */
    private const LINKS = [
        'condor_commercial_plan_version_vertical' => 'vertical_id',
        'condor_commercial_plan_version_capability' => 'capability_id',
        'condor_commercial_plan_version_addon' => 'addon_id',
    ];

    public function getDescription(): string
    {
        return 'Catálogo comercial v3 aprobado: nuevas tarifas COP, historia y relaciones intactas.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('condor_commercial_plan_version')) {
            // Primer despliegue o dry-run sobre esquema vacío: el seeder crea v1/v2/v3.
            return;
        }

        $plans = $this->catalogVersions(2);
        if ($plans === []) {
            return;
        }
        $this->assertFourPlans($plans, 2);
        $v3 = $this->catalogVersions(3);
        if ($v3 !== []) {
            $this->assertFourPlans($v3, 3);
            $this->assertCanonicalPrices($v3);
            foreach ($plans as $key => $v2) {
                $this->abortIf(
                    $v2['effective_until'] === null
                    || $v2['effective_until'] !== $v3[$key]['effective_from'],
                    'Catálogo v3 preexistente con vigencias no canónicas; no sobrescribir.',
                );
            }
            return;
        }

        $this->abortIf(
            $this->countNewerVersions() !== 0,
            'Existe una versión comercial posterior a v2; se requiere conciliación explícita.',
        );

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
        foreach (self::PRICES as $key => [$monthly, $annual, $quoteRequired]) {
            $old = $plans[$key];
            $this->abortIf(
                $old['effective_until'] !== null
                || $at <= (string) $old['effective_from'],
                'La v2 no es vigente o la fecha UTC no permite una sucesora.',
            );
            $newId = UlidFactory::new();
            $this->addSql(
                'UPDATE condor_commercial_plan_version
                    SET effective_until = :at
                  WHERE id = :old_id AND effective_until IS NULL',
                ['at' => $at, 'old_id' => $old['id']],
            );
            $this->addSql(
                'INSERT INTO condor_commercial_plan_version
                    (id, plan_id, version_number, currency, monthly_amount, annual_amount,
                     quote_required, limits, effective_from, effective_until)
                 SELECT :new_id, plan_id, 3, currency, :monthly, :annual, :quote_required,
                        limits, :at, NULL
                   FROM condor_commercial_plan_version WHERE id = :old_id',
                [
                    'new_id' => $newId,
                    'monthly' => $monthly,
                    'annual' => $annual,
                    'quote_required' => $quoteRequired ? 1 : 0,
                    'at' => $at,
                    'old_id' => $old['id'],
                ],
            );
            foreach (self::LINKS as $table => $column) {
                $this->addSql(
                    "INSERT INTO $table (plan_version_id, $column)
                     SELECT :new_id, $column FROM $table WHERE plan_version_id = :old_id",
                    ['new_id' => $newId, 'old_id' => $old['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('condor_commercial_plan_version')) {
            return;
        }
        $v3 = $this->catalogVersions(3);
        if ($v3 === []) {
            return;
        }
        $this->assertFourPlans($v3, 3);
        $this->assertCanonicalPrices($v3);
        $v2 = $this->catalogVersions(2);
        $this->assertFourPlans($v2, 2);
        $this->abortIf(
            $this->countNewerVersions() !== 4,
            'Hay versiones posteriores o faltantes: no revertir historial ajeno.',
        );
        foreach (self::PRICES as $key => $_prices) {
            $new = $v3[$key];
            $old = $v2[$key];
            $this->abortIf(
                $new['effective_until'] !== null
                || $old['effective_until'] !== $new['effective_from'],
                'La vigencia ya cambió: no destruir historial comercial.',
            );
            foreach (['condor_commercial_quote', 'condor_commercial_subscription'] as $table) {
                $references = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM $table WHERE plan_version_id = :id",
                    ['id' => $new['id']],
                );
                if ($references > 0) {
                    throw new IrreversibleMigration(
                        'La v3 tiene cotizaciones o suscripciones; no se elimina historial referenciado.',
                    );
                }
            }
            // Las tres relaciones de v3 usan ON DELETE CASCADE. Nunca borrar v1/v2.
            $this->addSql(
                'DELETE FROM condor_commercial_plan_version WHERE id = :id AND version_number = 3',
                ['id' => $new['id']],
            );
            $this->addSql(
                'UPDATE condor_commercial_plan_version SET effective_until = NULL
                 WHERE id = :id AND version_number = 2 AND effective_until = :at',
                ['id' => $old['id'], 'at' => $new['effective_from']],
            );
        }
    }

    /** @return array<string,array{id:string,effective_from:string,effective_until:?string,monthly_amount:?int,annual_amount:?int,quote_required:int}> */
    private function catalogVersions(int $number): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.catalog_key, v.id, v.effective_from, v.effective_until,
                    v.monthly_amount, v.annual_amount, v.quote_required
               FROM condor_commercial_plan p
               JOIN condor_commercial_plan_version v ON v.plan_id = p.id
              WHERE v.version_number = :version AND p.catalog_key
                    IN ('basic', 'business', 'pro', 'enterprise')",
            ['version' => $number],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['catalog_key']] = [
                'id' => (string) $row['id'],
                'effective_from' => (string) $row['effective_from'],
                'effective_until' => $row['effective_until'] === null ? null : (string) $row['effective_until'],
                'monthly_amount' => $row['monthly_amount'] === null ? null : (int) $row['monthly_amount'],
                'annual_amount' => $row['annual_amount'] === null ? null : (int) $row['annual_amount'],
                'quote_required' => (int) $row['quote_required'],
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $plans */
    private function assertFourPlans(array $plans, int $version): void
    {
        $keys = array_keys($plans);
        sort($keys);
        $expected = array_keys(self::PRICES);
        sort($expected);
        $this->abortIf($keys !== $expected, "Catálogo v$version incompleto; fallar cerrado.");
    }

    /** @param array<string,array{monthly_amount:?int,annual_amount:?int,quote_required:int}> $plans */
    private function assertCanonicalPrices(array $plans): void
    {
        foreach (self::PRICES as $key => [$monthly, $annual, $quoteRequired]) {
            $row = $plans[$key];
            $this->abortIf(
                $row['monthly_amount'] !== $monthly
                || $row['annual_amount'] !== $annual
                || $row['quote_required'] !== (int) $quoteRequired,
                'Tarifas v3 preexistentes diferentes: no sobreescribir.',
            );
        }
    }

    private function countNewerVersions(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM condor_commercial_plan_version v
              JOIN condor_commercial_plan p ON p.id = v.plan_id
             WHERE v.version_number >= 3
               AND p.catalog_key IN ('basic', 'business', 'pro', 'enterprise')",
        );
    }
}
