#!/usr/bin/env python3
"""#687: aceptación de identificadores escaneables, con fixtures sintéticos."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDENTIFIER = ROOT / "src/Domain/Identifiers/ScanIdentifier.php"
RESOLVER = ROOT / "src/Application/Identifiers/ScanResolver.php"

# Tipos de dominio sintéticos: no hay DB, usuarios, red ni secretos.
FIXTURE = r"""
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $identifier) {}
        public function id(): string { return $this->identifier; }
    }
}
namespace App\Domain\Catalog\Entity {
    final class ProductVariant {
        public function __construct(
            private \App\Domain\Organization\Entity\Tenant $owner,
            private string $identifier,
            private bool $active = true,
        ) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant { return $this->owner; }
        public function id(): string { return $this->identifier; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace {
    require __DIR__ . '/src/Domain/Identifiers/ScanIdentifier.php';
    require __DIR__ . '/src/Application/Identifiers/ScanResolver.php';
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Identifiers\ScanIdentifier;
    use App\Application\Identifiers\ScanResolver;

    $alpha = new Tenant('TENANT-ALPHA');
    $beta = new Tenant('TENANT-BETA');
    $variantAlpha = new ProductVariant($alpha, 'VARIANT-A');
    $variantOther = new ProductVariant($alpha, 'VARIANT-B');
    $variantBeta = new ProductVariant($beta, 'VARIANT-X');
    $resolver = new ScanResolver();
    $first = ScanIdentifier::forVariant($alpha, $variantAlpha, '  scan-0001  ');
    $resolver->register($first);
    $resolver->register(ScanIdentifier::forVariant($alpha, $variantAlpha, 'SCAN-0001'));
    $resolver->register(ScanIdentifier::forVariant($beta, $variantBeta, 'SCAN-0001'));
    $resolver->register(ScanIdentifier::forUnit($alpha, 'UNIT-17', 'UNIT-0017'));

    $duplicateDenied = false;
    try {
        $resolver->register(ScanIdentifier::forVariant($alpha, $variantOther, 'scan-0001'));
    } catch (\DomainException) { $duplicateDenied = true; }

    $crossTenantDenied = false;
    try {
        ScanIdentifier::forVariant($beta, $variantAlpha, 'SCAN-9999');
    } catch (\DomainException) { $crossTenantDenied = true; }

    $inactiveDenied = false;
    try {
        ScanIdentifier::forVariant($alpha, new ProductVariant($alpha, 'INACTIVE', false), 'SCAN-9999');
    } catch (\DomainException) { $inactiveDenied = true; }

    $malformedDenied = false;
    try {
        ScanIdentifier::forUnit($alpha, 'UNIT-18', '<script>');
    } catch (\DomainException) { $malformedDenied = true; }

    $resolved = $resolver->resolve($alpha, 'scan-0001');
    $betaResolved = $resolver->resolve($beta, 'SCAN-0001');
    $unit = $resolver->resolve($alpha, 'UNIT-0017');
    echo json_encode([
        'variant' => $resolved?->entityId(),
        'kind' => $resolved?->kind(),
        'action' => $resolved?->action(),
        'canonical' => $resolved?->code(),
        'sameCodeOtherTenant' => $betaResolved?->entityId(),
        'tenantDenied' => $resolver->resolve($beta, 'UNIT-0017') === null,
        'unitId' => $unit?->entityId(),
        'unitKind' => $unit?->kind(),
        'unitAction' => $unit?->action(),
        'unknownDenied' => $resolver->resolve($alpha, 'SCAN-9999') === null,
        'malformedResolutionDenied' => $resolver->resolve($alpha, '<script>') === null,
        'duplicateDenied' => $duplicateDenied,
        'crossTenantDenied' => $crossTenantDenied,
        'inactiveDenied' => $inactiveDenied,
        'malformedDenied' => $malformedDenied,
    ], JSON_THROW_ON_ERROR);
}
"""


class IdentificadoresScanTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (IDENTIFIER, RESOLVER):
            self.assertTrue(path.is_file(), str(path))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI necesario para probar el dominio")
        for path in (IDENTIFIER, RESOLVER):
            lint = subprocess.run(
                [php, "-l", str(path)], capture_output=True, text=True, check=False
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        result = subprocess.run(
            [php, "-r", FIXTURE],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        value = json.loads(result.stdout)
        self.assertEqual("VARIANT-A", value["variant"])
        self.assertEqual("variant", value["kind"])
        self.assertEqual("view_variant", value["action"])
        self.assertEqual("SCAN-0001", value["canonical"])
        self.assertEqual("VARIANT-X", value["sameCodeOtherTenant"])
        self.assertEqual("UNIT-17", value["unitId"])
        self.assertEqual("unit", value["unitKind"])
        self.assertEqual("view_unit", value["unitAction"])
        for key in (
            "tenantDenied", "unknownDenied", "malformedResolutionDenied",
            "duplicateDenied", "crossTenantDenied", "inactiveDenied",
            "malformedDenied",
        ):
            with self.subTest(key=key):
                self.assertTrue(value[key], key)


if __name__ == "__main__":
    unittest.main()
