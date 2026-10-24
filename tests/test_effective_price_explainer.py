#!/usr/bin/env python3
"""Condor #864: precio efectivo explicable, escenario PHP real y sin red/DB."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Commerce/EffectivePriceExplainer.php"
CONTROLLER = ROOT / "src/Http/Controller/EffectivePriceController.php"

# Ejecuta los servicios y entidades PHP reales con un repositorio de memoria.
# Solo el transporte Doctrine y ULID se reemplazan por fakes, sin DB ni PII.
PHP_SCENARIO = r"""<?php
declare(strict_types=1);

namespace Doctrine\ORM {
    interface EntityManagerInterface
    {
        public function getRepository(string $entityName): object;
    }
}
namespace Symfony\Component\Uid {
    final class Ulid
    {
        private static int $next = 0;
        private string $value;
        public function __construct()
        {
            $this->value = str_pad((string) ++self::$next, 26, '0', STR_PAD_LEFT);
        }
        public function __toString(): string { return $this->value; }
    }
}
namespace {
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $s, ?string $encoding = null): int { return strlen($s); }
    }
    if (!function_exists('mb_strtoupper')) {
        function mb_strtoupper(string $s, ?string $encoding = null): string { return strtoupper($s); }
    }

    $root = $argv[1];
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) { return; }
        $path = $root.'/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($path)) { require_once $path; }
    });

    use App\Application\Commerce\EffectivePriceExplainer;
    use App\Application\Commerce\PricingService;
    use App\Domain\Catalog\Entity\Product;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Commerce\EffectivePriceResolver;
    use App\Domain\Commerce\Entity\PriceList;
    use App\Domain\Commerce\Entity\PriceRule;
    use App\Domain\Commerce\Entity\SalesChannel;
    use App\Domain\Commerce\Entity\VariantPrice;
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Organization\Entity\Tenant;
    use Doctrine\ORM\EntityManagerInterface;

    function check(bool $condition, string $reason): void {
        if (!$condition) { throw new \RuntimeException($reason); }
    }

    final class MemoryRepo {
        /** @param array<int, object> $items */
        public function __construct(public array $items) {}
        public function findBy(array $criteria): array {
            return array_values(array_filter($this->items, static function (object $item) use ($criteria): bool {
                foreach ($criteria as $key => $expected) {
                    $actual = $key === 'active' ? $item->isActive() : $item->$key();
                    if (is_array($expected)) {
                        $ids = array_map(static fn (object $ref): string => $ref->id(), $expected);
                        if (!in_array($actual->id(), $ids, true)) { return false; }
                    } elseif (is_object($expected)) {
                        if ($actual->id() !== $expected->id()) { return false; }
                    } elseif ($actual !== $expected) {
                        return false;
                    }
                }
                return true;
            }));
        }
    }

    final class MemoryManager implements EntityManagerInterface {
        /** @param array<string, MemoryRepo> $repositories */
        public function __construct(public array $repositories) {}
        public function getRepository(string $entityName): object {
            return $this->repositories[$entityName] ?? new MemoryRepo([]);
        }
    }

    $a = new Tenant('Prueba Condor A', 'test-condor-a');
    $b = new Tenant('Prueba Condor B', 'test-condor-b');
    $legalA = new LegalEntity($a, 'Condor A SAS', null);
    $legalB = new LegalEntity($b, 'Condor B SAS', null);
    $inventoryA = new InventorySource($a, $legalA, 'Stock A', 'stock-a', InventorySource::TYPE_LOGICAL);
    $inventoryB = new InventorySource($b, $legalB, 'Stock B', 'stock-b', InventorySource::TYPE_LOGICAL);
    $listA = new PriceList($a, 'Lista A', 'lista-a');
    $listB = new PriceList($b, 'Lista B', 'lista-b');
    $channelA = new SalesChannel($a, 'Web A', 'web-a', $inventoryA, $listA);
    $channelB = new SalesChannel($b, 'Web B', 'web-b', $inventoryB, $listB);
    $productA = new Product($a, 'Producto A', 'producto-a');
    $productB = new Product($b, 'Producto B', 'producto-b');
    $variantA = new ProductVariant($a, $productA, 'SKU-A', 'A');
    $variantB = new ProductVariant($b, $productB, 'SKU-B', 'B');
    $priceA = new VariantPrice($a, $listA, $variantA, 10000);
    $priceB = new VariantPrice($b, $listB, $variantB, 123456);
    $now = new \DateTimeImmutable('2026-10-24T12:00:00+00:00');
    $from = new \DateTimeImmutable('2026-10-01T00:00:00+00:00');
    $until = new \DateTimeImmutable('2026-11-01T00:00:00+00:00');
    $expired = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $r1 = new PriceRule($a, $listA, 'Descuento fijo A', 20, PriceRule::TYPE_FIXED, 1000, null, $from, $until);
    $r2 = new PriceRule($a, $listA, 'Descuento fijo B', 20, PriceRule::TYPE_FIXED, 1800, null, $from, $until);
    $rExpired = new PriceRule($a, $listA, 'Expirado', 99, PriceRule::TYPE_FIXED, 9500, null, new \DateTimeImmutable('2025-01-01T00:00:00+00:00'), $expired);
    $rForeign = new PriceRule($b, $listB, 'No filtrar', 100, PriceRule::TYPE_FIXED, 5000);
    $ruleRepo = new MemoryRepo([$rForeign, $r2, $rExpired, $r1]);
    $manager = new MemoryManager([
        VariantPrice::class => new MemoryRepo([$priceB, $priceA]),
        PriceRule::class => $ruleRepo,
    ]);
    $service = new PricingService($manager, new EffectivePriceResolver());
    $explainer = new EffectivePriceExplainer($service, $manager);

    $one = $explainer->explain($a, $variantA, $channelA, 1, $now);
    $ruleRepo->items = array_reverse($ruleRepo->items);
    $again = $explainer->explain($a, $variantA, $channelA, 1, $now);
    check($one === $again, 'Resolver distinto con entrada idéntica y reglas reordenadas');
    $winner = strcmp($r1->id(), $r2->id()) < 0 ? $r1 : $r2;
    check($one['selected_rule_id'] === $winner->id(), 'Precedencia ID ante empate no determinista');
    check($one['unit_amount_minor'] === $winner->applyTo(10000), 'Importe distinto de PricingService');
    check($one['price_list_id'] === $listA->id(), 'Lista no corresponde al canal');
    check($one['rules'][0]['priority'] === 99, 'Traza no ordena las reglas por prioridad');
    check($one['rules'][0]['reason'] === 'expired', 'Regla expirada incorrectamente elegible');
    check(count(array_filter($one['rules'], static fn (array $r): bool => $r['applied'])) === 1, 'Debe haber una ganadora');
    check(!in_array($rForeign->id(), array_column($one['rules'], 'rule_id'), true), 'Filtración de regla de otro tenant');
    check($one['quantity_policy'] === 'unit_price_times_quantity_no_volume_rules', 'Se inventó descuento por volumen');

    $three = $explainer->explain($a, $variantA, $channelA, 3, $now);
    check($three['unit_amount_minor'] === $one['unit_amount_minor'], 'La cantidad alteró el precio unitario');
    check($three['total_amount_minor'] === 3 * $one['unit_amount_minor'], 'El total no escala correctamente');
    check($three['rules'] === $one['rules'], 'El orden de reglas cambió con cantidad');
    check($three['evaluated_at'] === '2026-10-24T12:00:00+00:00', 'Fecha no explícita/estable');
    foreach ([0, 1001] as $invalid) {
        try {
            $explainer->explain($a, $variantA, $channelA, $invalid, $now);
            throw new \RuntimeException('Cantidad inválida aceptada');
        } catch (\DomainException $expected) {
            // Fail closed; no writes.
        }
    }
    foreach ([[$variantB, $channelA], [$variantA, $channelB]] as [$variant, $channel]) {
        try {
            $explainer->explain($a, $variant, $channel, 1, $now);
            throw new \RuntimeException('Datos ajenos aceptados');
        } catch (\DomainException $expected) {
            // Cross-tenant access rejected even in direct service calls.
        }
    }
    echo "PHP pricing explanation deterministic, quantity-safe, tenant-isolated: OK\n";
}
"""


class PrecioEfectivoExplicadoTests(unittest.TestCase):
    def test_acceptance(self):
        self.assertTrue(SERVICE.is_file(), "Falta el servicio real de explicación")
        self.assertTrue(CONTROLLER.is_file(), "Falta el endpoint HTTP")
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("pricing.view", controller)
        self.assertIn("authorizedBranchScope", controller)
        self.assertIn("tenant' => $tenant", controller)
        self.assertIn("methods: ['GET']", controller)
        self.assertIn("isPublishable()", controller)
        self.assertIn("UnprocessableEntityHttpException", controller)

        php = shutil.which("php")
        self.assertIsNotNone(php, "AC-01 requiere PHP real, no una prueba simulada")
        with tempfile.TemporaryDirectory(prefix="condor-price-explain-") as tmp:
            script = Path(tmp) / "effective-price-scenario.php"
            script.write_text(PHP_SCENARIO, encoding="utf-8")
            result = subprocess.run(
                [php, str(script), str(ROOT)],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=40,
                check=False,
            )
        self.assertEqual(0, result.returncode, result.stdout + "\n" + result.stderr)
        self.assertIn("tenant-isolated: OK", result.stdout)


if __name__ == "__main__":
    unittest.main()
