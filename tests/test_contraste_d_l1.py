#!/usr/bin/env python3
"""Condor #1180: contrasts for D-003/D-018/D-020/D-027.

Runs *real* domain PHP methods against synthetic tenants, alongside limited
Twig and matrix contracts. These are code-level regressions, not evidence of
complete HTTP/DB security, locale negotiation, UI usability, or live readiness.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"
EXPECTED = {
    "D-003": ("Español de Colombia como idioma principal",
              ("fallback", "es-CO", "rutas públicas", "administración")),
    "D-018": ("Simple por defecto y altamente configurable por empresa",
              ("defaults", "overrides", "tenant", "roles CRUD", "sedes")),
    "D-020": ("Configuración progresiva sin proliferación de pantallas",
              ("cross-tenant", "sede", "privilegio mínimo")),
    "D-027": ("Canales, sedes, inventario y precio efectivo",
              ("cross-tenant", "sede", "privilegio mínimo")),
}
PHP_SOURCES = (
    "src/Domain/Identity/PermissionCatalog.php",
    "src/Domain/Organization/Entity/Tenant.php",
    "src/Domain/Organization/Entity/LegalEntity.php",
    "src/Domain/Organization/Entity/Branch.php",
    "src/Domain/Identity/Entity/Role.php",
    "src/Domain/Inventory/Entity/InventorySource.php",
    "src/Domain/Commerce/Entity/CommercialRecord.php",
    "src/Domain/Commerce/Entity/CommercialItem.php",
    "src/Domain/Commerce/Entity/NamedCommercialItem.php",
    "src/Domain/Commerce/Entity/PriceList.php",
    "src/Domain/Commerce/Entity/SalesChannel.php",
)
TEMPLATES = (
    "templates/base.html.twig",
    "templates/home/index.html.twig",
    "templates/tenant/index.html.twig",
    "templates/security/login.html.twig",
)

# Synthetic ID factory is the *only* stand-in. All domain checks below execute
# real PHP classes; the fixture never opens a DB/HTTP connection or uses secrets.
PHP_PROBE = r"""<?php
declare(strict_types=1);
namespace App\Shared\Id {
    final class UlidFactory {
        private static int $counter = 0;
        public static function new(): string {
            return sprintf('%026d', ++self::$counter);
        }
    }
}
namespace {
    if (!function_exists('mb_strlen')) {
        function mb_strlen(string $value, ?string $encoding = null): int {
            return strlen($value);
        }
    }
    if (!function_exists('mb_strtoupper')) {
        function mb_strtoupper(string $value, ?string $encoding = null): string {
            return strtoupper($value);
        }
    }
    foreach ([
        'src/Domain/Identity/PermissionCatalog.php',
        'src/Domain/Organization/Entity/Tenant.php',
        'src/Domain/Organization/Entity/LegalEntity.php',
        'src/Domain/Organization/Entity/Branch.php',
        'src/Domain/Identity/Entity/Role.php',
        'src/Domain/Inventory/Entity/InventorySource.php',
        'src/Domain/Commerce/Entity/CommercialRecord.php',
        'src/Domain/Commerce/Entity/CommercialItem.php',
        'src/Domain/Commerce/Entity/NamedCommercialItem.php',
        'src/Domain/Commerce/Entity/PriceList.php',
        'src/Domain/Commerce/Entity/SalesChannel.php',
    ] as $source) {
        require_once getcwd().'/'.$source;
    }
    use App\Domain\Identity\PermissionCatalog;
    use App\Domain\Identity\Entity\Role;
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\LegalEntity;
    use App\Domain\Organization\Entity\Branch;
    use App\Domain\Inventory\Entity\InventorySource;
    use App\Domain\Commerce\Entity\PriceList;
    use App\Domain\Commerce\Entity\SalesChannel;
    function denied(callable $action): bool {
        try { $action(); } catch (\DomainException $error) { return true; }
        return false;
    }
    $a = new Tenant('Synthetic A', 'synthetic-a');
    $b = new Tenant('Synthetic B', 'synthetic-b');
    $legalA = new LegalEntity($a, 'Synthetic A SAS', null, true);
    $legalB = new LegalEntity($b, 'Synthetic B SAS', null, true);
    $branchA = new Branch($a, 'Main', 'main', $legalA, true);
    $branchSecond = new Branch($a, 'Second', 'second', $legalA);
    $branchB = new Branch($b, 'Foreign', 'foreign', $legalB, true);
    $viewerA = new Role($a, 'Viewer', ['catalog.view', 'orders.view']);
    $ownerB = new Role($b, 'Other tenant', ['roles.create']);
    $defaults = $branchA->isDefault() && !$branchSecond->isDefault();
    $leastPrivilege = $viewerA->permissions() === ['catalog.view', 'orders.view']
        && !in_array('roles.create', $viewerA->permissions(), true)
        && $ownerB->tenant()->id() !== $viewerA->tenant()->id();
    $viewerA->update('Catalog editor', ['catalog.update', 'catalog.view']);
    $overrides = $viewerA->permissions() === ['catalog.update', 'catalog.view']
        && !in_array('orders.view', $viewerA->permissions(), true)
        && $ownerB->permissions() === ['roles.create'];
    $roleRejectsUnknown = denied(fn() => new Role($a, 'Unknown', ['roles.superuser']))
        && denied(fn() => PermissionCatalog::normalize(['catalog.view', 'orders.grant']))
        && denied(fn() => PermissionCatalog::normalizeActions(['view', 'grant']));
    $branchDeniesForeignLegal = denied(fn() => new Branch(
        $a, 'Foreign Legal', 'foreign-legal', $legalB, true
    ));
    $sourceA = new InventorySource(
        $a, $legalA, 'Primary source', 'primary-source',
        InventorySource::TYPE_BRANCH, $branchA
    );
    $sourceB = new InventorySource(
        $b, $legalB, 'Foreign source', 'foreign-source',
        InventorySource::TYPE_BRANCH, $branchB
    );
    $logical = new InventorySource(
        $a, $legalA, 'Logical channel stock', 'logical-channel',
        InventorySource::TYPE_LOGICAL
    );
    $validSources = $sourceA->branch()->id() === $branchA->id()
        && $logical->branch() === null
        && $logical->type() === InventorySource::TYPE_LOGICAL;
    $invalidSources = denied(fn() => new InventorySource(
        $a, $legalB, 'Foreign entity', 'foreign-entity',
        InventorySource::TYPE_LOGICAL
    )) && denied(fn() => new InventorySource(
        $a, $legalA, 'Foreign branch', 'foreign-branch',
        InventorySource::TYPE_BRANCH, $branchB
    )) && denied(fn() => new InventorySource(
        $a, $legalA, 'Wrong logical', 'wrong-logical',
        InventorySource::TYPE_LOGICAL, $branchA
    )) && denied(fn() => new InventorySource(
        $a, $legalA, 'Wrong missing', 'wrong-missing',
        InventorySource::TYPE_BRANCH
    ));
    $priceA = new PriceList($a, 'Standard price', 'standard');
    $priceB = new PriceList($b, 'Foreign price', 'foreign-price');
    $channel = new SalesChannel($a, 'Online', 'online', $sourceA, $priceA);
    $pricing = $priceA->currency() === 'COP'
        && $channel->inventorySource()->id() === $sourceA->id()
        && $channel->priceList()->id() === $priceA->id()
        && $channel->isPublishable();
    $foreignChannels = denied(fn() => new SalesChannel(
        $a, 'Foreign Price', 'foreign-price', $sourceA, $priceB
    )) && denied(fn() => new SalesChannel(
        $a, 'Foreign Stock', 'foreign-stock', $sourceB, $priceA
    ));
    $sourceA->deactivate();
    $closedWhenInactive = !$channel->isPublishable();
    echo json_encode([
        'D-018' => $defaults && $leastPrivilege && $overrides,
        'D-018-denied' => $roleRejectsUnknown && $branchDeniesForeignLegal,
        'D-020' => $leastPrivilege && $branchDeniesForeignLegal,
        'D-020-denied' => $roleRejectsUnknown && $branchDeniesForeignLegal,
        'D-027' => $validSources && $pricing,
        'D-027-denied' => $invalidSources && $foreignChannels
            && $closedWhenInactive,
    ], JSON_THROW_ON_ERROR);
}
"""


def matrix_states(document: object) -> dict[str, str]:
    """Reject missing, duplicated, malformed, or silently promoted decision rows."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: missing coverage matrix")
    result: dict[str, str] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*`(D-(?:003|018|020|027))`(?=\s|\W)", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in result or len(cells) != 5 or not all(cells)
                or cells[0] != f"`{key}` · `ESPECIFICACIONES.md`"
                or cells[1] != EXPECTED[key][0]
                or cells[2] != "`NO_VERIFICABLE`"
                or cells[4] != "#1"
                or key not in cells[3]
                or "Veredicto" not in cells[3]):
            raise ValueError("UNKNOWN: invalid coverage row " + key)
        for term in EXPECTED[key][1]:
            if term.casefold() not in cells[3].casefold():
                raise ValueError("UNKNOWN: missing clause in " + key + ": " + term)
        result[key] = "NO_VERIFICABLE"
    if set(result) != set(EXPECTED):
        raise ValueError("UNKNOWN: missing decision coverage")
    return result


def language_contract(sources: object) -> str:
    """Only verifies inherited Twig markup, not actual HTTP locale negotiation."""
    if not isinstance(sources, dict) or set(sources) != set(TEMPLATES):
        return UNKNOWN
    if any(not isinstance(sources[p], str) for p in TEMPLATES):
        return UNKNOWN
    base = sources[TEMPLATES[0]]
    if len(re.findall(r'<html\s+lang="es-CO">', base)) != 1:
        return UNKNOWN
    for path in TEMPLATES[1:]:
        if "{% extends 'base.html.twig' %}" not in sources[path]:
            return UNKNOWN
        if re.search(r"<html\b|\blang\s*=", sources[path]):
            return UNKNOWN
    if "Ingresar" not in sources[TEMPLATES[-1]]:
        return UNKNOWN
    if "Catálogo" not in sources[TEMPLATES[2]]:
        return UNKNOWN
    return LOCAL_PARTIAL


def integral_status(gap: str, matrix: object, evidence: object = None) -> str:
    """Offline tests never certify the full tenant/locale/UI/deploy contract."""
    if gap not in EXPECTED:
        return UNKNOWN
    try:
        rows = matrix_states(matrix)
    except ValueError:
        return UNKNOWN
    if rows[gap] != "NO_VERIFICABLE":
        return UNKNOWN
    return UNKNOWN


def php_regressions() -> dict[str, bool]:
    """Run actual PHP domain guards with synthetic input; fail if unavailable."""
    for source in PHP_SOURCES:
        if not (ROOT / source).is_file():
            raise AssertionError("UNKNOWN: missing PHP domain source " + source)
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP executable required, no fake pass")
    proc = subprocess.run([php], input=PHP_PROBE, text=True, cwd=ROOT,
                          capture_output=True, check=False, timeout=30)
    if proc.returncode:
        raise AssertionError("UNKNOWN: PHP domain probe failed: " + proc.stderr[-900:])
    result = json.loads(proc.stdout)
    if not isinstance(result, dict) or set(result) != {
        "D-018", "D-018-denied", "D-020", "D-020-denied",
        "D-027", "D-027-denied"
    } or any(type(v) is not bool for v in result.values()):
        raise AssertionError("UNKNOWN: missing PHP regression receipts")
    return result


class ContrasteDL1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.states = matrix_states(cls.matrix)

    def test_contrato_principal(self) -> None:
        """AC-01: enforce four independent decisions and real domain behavior."""
        self.assertEqual(self.states, {k: "NO_VERIFICABLE" for k in EXPECTED})
        templates = {p: (ROOT / p).read_text(encoding="utf-8")
                     for p in TEMPLATES}
        self.assertEqual(language_contract(templates), LOCAL_PARTIAL,
                         "D-003: inherited Colombian locale in public/admin Twig")
        result = php_regressions()
        for gap in ("D-018", "D-020", "D-027"):
            with self.subTest(gap=gap):
                self.assertTrue(result[gap], gap + ": real PHP domain regression")
                self.assertTrue(result[gap + "-denied"],
                                gap + ": cross-tenant / least-privilege denial")
        for gap in EXPECTED:
            self.assertEqual(integral_status(gap, self.matrix, result), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: prove failures for bad matrix/locale and domain permissions."""
        for gap in EXPECTED:
            line = next(x for x in self.matrix.splitlines()
                        if x.startswith(f"| `{gap}`"))
            for broken in (
                self.matrix.replace(line + "\n", "", 1),
                self.matrix + "\n" + line,
                self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                self.matrix.replace(line, line.replace("ESPECIFICACIONES.md", "fake.md"), 1),
            ):
                with self.subTest(gap=gap):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_states(broken)
                    self.assertEqual(integral_status(gap, broken, {"CI": "success"}), UNKNOWN)
        sources = {p: (ROOT / p).read_text(encoding="utf-8")
                   for p in TEMPLATES}
        changed = dict(sources)
        changed[TEMPLATES[0]] = changed[TEMPLATES[0]].replace('lang="es-CO"', 'lang="en-US"')
        self.assertEqual(language_contract(changed), UNKNOWN)
        for missing in (None, {}, {TEMPLATES[0]: sources[TEMPLATES[0]]}):
            self.assertEqual(language_contract(missing), UNKNOWN)
        result = php_regressions()
        self.assertTrue(all(result[k] for k in (
            "D-018-denied", "D-020-denied", "D-027-denied"
        )))

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic-only probe, local files, no HTTP/DB or real identities."""
        for marker in ("Synthetic A", "Synthetic B", "synthetic-a",
                       "synthetic-b", "Other tenant"):
            self.assertIn(marker, PHP_PROBE)
        for unsafe in ("https://", "curl_", "file_get_contents(",
                       "getenv(", "PDO(", "mysqli_"):
            self.assertNotIn(unsafe, PHP_PROBE)
        self.assertNotIn("@", PHP_PROBE)
        self.assertTrue(all((ROOT / p).is_file() for p in PHP_SOURCES + TEMPLATES))
        self.assertEqual(integral_status("D-018", self.matrix, {"user": "fake"}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 contract method: no fake success, coverage or live promotion."""
        for gap in EXPECTED:
            self.assertEqual(self.states[gap], "NO_VERIFICABLE")
            for claim in (
                None, {}, {"CI": "success"}, {"review": "approved"},
                {"tenant": "synthetic-a", "checked": True},
                {"status": "CUBIERTO", "all_routes": True},
                {"state": "VALIDATED_IN_PRODUCTION", "signed": True},
            ):
                with self.subTest(gap=gap, claim=claim):
                    self.assertEqual(integral_status(gap, self.matrix, claim), UNKNOWN)
        self.assertEqual(integral_status("D-999", self.matrix, True), UNKNOWN)
        self.assertEqual(integral_status("D-003", None, True), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
