#!/usr/bin/env python3
"""AC-01 #692: print-only tenant-scoped labels using the real scan identifier contract."""

from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDENTIFIER = ROOT / "src/Domain/Identifiers/ScanIdentifier.php"
TEMPLATE = ROOT / "src/Application/Labels/LabelTemplate.php"
RENDER = ROOT / "src/Application/Labels/LabelRenderer.php"

# Fake entities implement only the production public domain contract.
# Identifier creation and validation use the real ScanIdentifier.php.
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
            private string $displayName,
            private bool $active = true,
        ) {}
        public function tenant(): \App\Domain\Organization\Entity\Tenant { return $this->owner; }
        public function id(): string { return $this->identifier; }
        public function name(): string { return $this->displayName; }
        public function isActive(): bool { return $this->active; }
    }
}
namespace {
    require __DIR__.'/src/Domain/Identifiers/ScanIdentifier.php';
    require __DIR__.'/src/Application/Labels/LabelTemplate.php';
    require __DIR__.'/src/Application/Labels/LabelRenderer.php';
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Identifiers\ScanIdentifier;
    use App\Application\Labels\LabelTemplate;
    use App\Application\Labels\LabelRenderer;

    $alpha = new Tenant('TENANT-ALPHA');
    $beta = new Tenant('TENANT-BETA');
    $variant = new ProductVariant($alpha, 'VAR-1', 'Café & <script>alert(1)</script> "premium"');
    $otherVariant = new ProductVariant($alpha, 'VAR-2', 'Otro producto');
    $identifier = ScanIdentifier::forVariant($alpha, $variant, ' scan-0001 ');
    $renderer = new LabelRenderer();
    $template = LabelTemplate::standard();
    $output = $renderer->render($alpha, $variant, $identifier, $template);
    $deny = static function (callable $callback): bool {
        try { $callback(); } catch (\DomainException) { return true; }
        return false;
    };

    $checks = [
        'deterministic' => $output === $renderer->render($alpha, $variant, $identifier, $template),
        'variantNameEscaped' => str_contains($output, 'Café &amp; &lt;script&gt;alert(1)&lt;/script&gt; &quot;premium&quot;'),
        'noLiteralScript' => !str_contains($output, '<script'),
        'codeCanonical' => str_contains($output, 'data-scan-code="SCAN-0001"')
            && str_contains($output, '>SCAN-0001</div>'),
        'printableSize' => str_contains($output, '@page{size:60mm 40mm;margin:0}'),
        'otherTenantNotRendered' => $deny(fn() => $renderer->render($beta, $variant, $identifier, $template)),
        'otherVariantNotRendered' => $deny(fn() => $renderer->render($alpha, $otherVariant, $identifier, $template)),
        'wrongKindDenied' => $deny(fn() => $renderer->render($alpha, $variant, ScanIdentifier::forUnit($alpha, 'UNIT-01', 'UNIT-0001'), $template)),
        'inactiveDenied' => $deny(fn() => $renderer->render($alpha, new ProductVariant($alpha, 'VAR-1', 'inactive', false), $identifier, $template)),
        'invalidDimensionsDenied' => $deny(fn() => new LabelTemplate('Size', 1000, 1)),
        'emptyNameDenied' => $deny(fn() => new LabelTemplate('  ', 60, 40)),
        'idCrossTenantDenied' => $deny(fn() => ScanIdentifier::forVariant($beta, $variant, 'CROSS-0001')),
        'codeMalformedDenied' => $deny(fn() => ScanIdentifier::forVariant($alpha, $variant, '<script>')),
    ];
    echo json_encode($checks, JSON_THROW_ON_ERROR);
}
"""


class EtiquetasImprimiblesTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required")
        for path in (IDENTIFIER, TEMPLATE, RENDER):
            self.assertTrue(path.is_file(), str(path))
            result = subprocess.run(
                [php, "-l", str(path)], capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        result = subprocess.run(
            [php, "-r", FIXTURE], cwd=ROOT, capture_output=True, text=True, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        checks = json.loads(result.stdout)
        for name, passed in checks.items():
            with self.subTest(contract=name):
                self.assertTrue(passed, name)


if __name__ == "__main__":
    unittest.main()
