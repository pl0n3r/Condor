#!/usr/bin/env python3
"""Condor #1684: catálogo comercial v3, historia y HTTP sin cobro real."""
from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATION = ROOT / "migrations/Version20261106140000.php"
SEEDER = ROOT / "src/Application/Commercial/CommercialCatalogSeeder.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\PlanConfiguratorCatalogReader;
use App\Application\Commercial\PlanQuoteService;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanesV3PreciosTest extends WebTestCase
{
    public function testV3HistoryConfiguratorQuoteAndPublicPrices(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $seeder = new CommercialCatalogSeeder($em);
        $seeder->seed();
        $seeder->seed();
        $reader = new CommercialCatalogReader($em, new PlanVersionTimeline());
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $current = array_column($reader->current($at), null, 'key');
        self::assertCount(4, $current);
        $tariffs = [
            'basic' => [79900, 799000, ['companies'=>1,'locations'=>1,'users'=>3]],
            'business' => [199900, 1999000, ['companies'=>1,'locations'=>3,'users'=>10]],
            'pro' => [499900, 4999000, ['companies'=>5,'locations'=>10,'users'=>30]],
        ];
        foreach ($tariffs as $key => [$monthly, $annual, $limits]) {
            self::assertSame(3, $current[$key]['version']);
            self::assertSame('COP', $current[$key]['currency']);
            self::assertFalse($current[$key]['quote_required']);
            self::assertSame($monthly, $current[$key]['monthly_amount']);
            self::assertSame($annual, $current[$key]['annual_amount']);
            self::assertSame($limits, $current[$key]['limits']);
        }
        self::assertSame(3, $current['enterprise']['version']);
        self::assertTrue($current['enterprise']['quote_required']);
        self::assertNull($current['enterprise']['monthly_amount']);
        self::assertNull($current['enterprise']['annual_amount']);

        $v1 = array_column($reader->current(new DateTimeImmutable('2026-09-27T12:00:00Z')), null, 'key');
        $v2 = array_column($reader->current(new DateTimeImmutable('2026-10-31T12:00:00Z')), null, 'key');
        foreach (array_keys($current) as $key) {
            self::assertSame(1, $v1[$key]['version']);
            self::assertSame(2, $v2[$key]['version']);
            self::assertTrue($v2[$key]['quote_required']);
            self::assertNull($v2[$key]['monthly_amount']);
            self::assertNull($v2[$key]['annual_amount']);
            foreach (['limits', 'verticals', 'capabilities', 'addons'] as $relation) {
                self::assertSame($v2[$key][$relation], $current[$key][$relation]);
            }
        }
        self::assertSame(79900, $v1['basic']['monthly_amount']);

        $configurator = new PlanConfiguratorCatalogReader($reader, $em);
        $options = $configurator->options('basic', 'commerce', $at);
        self::assertSame(3, $options['plan']['version']);
        self::assertSame(79900, $options['plan']['monthly_amount']);

        $quoteService = new PlanQuoteService($configurator, $em);
        foreach (['monthly' => 79900, 'annual' => 799000] as $cycle => $base) {
            $quote = $quoteService->preview(
                'basic', 'commerce', $cycle,
                ['companies'=>1,'users'=>3,'locations'=>1], [], $at,
            );
            self::assertSame(3, $quote->planVersion()->version());
            self::assertSame($base, $quote->baseAmount());
            self::assertSame($base, $quote->totalAmount());
            self::assertFalse($quote->proposalRequired());
        }

        $client->request('GET', '/precios');
        self::assertResponseIsSuccessful();
        $cards = $client->getCrawler()->filter('article.pricing-card');
        self::assertCount(4, $cards);
        foreach ($tariffs as $key => [$monthly, $annual]) {
            $card = $cards->reduce(
                static fn ($node) => $node->getAttribute('data-plan-key') === $key,
            )->first();
            self::assertSame('3', $card->attr('data-plan-version'));
            self::assertStringContainsString('$ '.number_format($monthly, 0, ',', '.'), $card->text());
            self::assertStringContainsString('$ '.number_format($annual, 0, ',', '.'), $card->text());
        }
        $enterprise = $cards->reduce(
            static fn ($node) => $node->getAttribute('data-plan-key') === 'enterprise',
        )->first();
        self::assertStringContainsString('Solicitar propuesta', $enterprise->text());
        self::assertStringNotContainsString('0 COP', $enterprise->text());
    }
}
"""


class CatalogoPlanesV3PreciosTests(unittest.TestCase):
    def test_migration_is_additive_and_reversible_with_guards(self) -> None:
        """D-054 must never see data changes in the historic v3 schema dry-run."""
        source = MIGRATION.read_text(encoding="utf-8")
        up = source.split("public function up", 1)[1].split("public function down", 1)[0]
        down = source.split("public function down", 1)[1]
        for operation in (
            "addSql(", "executeStatement(", "executeQuery(",
            "prepare(", "query(",
        ):
            self.assertNotIn(operation, up)
        self.assertNotRegex(
            up, r"(?i)\b(?:UPDATE|INSERT|DELETE|DROP|TRUNCATE|ALTER|RENAME|MODIFY|CHANGE)\b",
        )
        self.assertIn("IrreversibleMigration", down)
        self.assertIn("throw new IrreversibleMigration(", down)
        self.assertIn("app:commercial:seed", up)
        post_deploy = (ROOT / "scripts/post-deploy.sh").read_text(encoding="utf-8")
        self.assertIn("run_construction_migrations", post_deploy)
        self.assertIn('set_post_deploy_phase "catalog-seed"', post_deploy)
        self.assertLess(
            post_deploy.index("run_construction_migrations"),
            post_deploy.index('set_post_deploy_phase "catalog-seed"'),
        )

    def test_seeder_keeps_v1_v2_and_publishes_v3(self) -> None:
        text = SEEDER.read_text(encoding="utf-8")
        for pattern in (
            r"'basic'\s*=>\s*\[79900,\s*799000,\s*false",
            r"'business'\s*=>\s*\[199900,\s*1999000,\s*false",
            r"'pro'\s*=>\s*\[499900,\s*4999000,\s*false",
            r"'enterprise'\s*=>\s*\[null,\s*null,\s*true",
            r"\$plans\[\$key\],\s*2,\s*null,\s*null,\s*true",
            r"\$plans\[\$key\],\s*3,\s*\$monthly,\s*\$annual,\s*\$quoteRequired",
        ):
            self.assertRegex(text, pattern)
        self.assertIn("$unpriced->closeAt($publishedAt)", text)

    def test_php_http_and_quote_are_canonical_v3(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI necesario para la prueba real")
        self.assertTrue(RUNNER.is_file(), "PHPUnit necesario para la prueba real")
        with tempfile.TemporaryDirectory(prefix="condor-v3-prices-") as directory:
            fixture = Path(directory) / "PlanesV3PreciosTest.php"
            fixture.write_text(PHP_SCENARIO, encoding="utf-8")
            for name, command in (
                ("lint", [php, "-l", str(fixture)]),
                ("web+quote", [str(RUNNER), str(fixture)]),
            ):
                run = subprocess.run(
                    command, cwd=ROOT, capture_output=True, text=True,
                    timeout=180, check=False,
                )
                self.assertEqual(0, run.returncode, f"{name}: {run.stdout}{run.stderr}")


if __name__ == "__main__":
    unittest.main()
