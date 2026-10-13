#!/usr/bin/env python3
"""Cobertura ejecutable de los tres contratos de self-service trial en CI."""
from __future__ import annotations

import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CI = ROOT / ".github/workflows/ci.yml"
REVIEW = ROOT / "src/Application/Commercial/SelfServiceTrialReview.php"
TRIAL_TESTS = (
    "test_self_service_trial_application.py",
    "test_self_service_trial_review.py",
    "test_self_service_trial_status.py",
)
QUOTE_ID = "01AAAAAAAAAAAAAAAAAAAAAAAA"

PHP_REVIEW_HARNESS = r"""
namespace App\Domain\Identity\Entity {
    class User {
        public const ROLE_PLATFORM_OWNER = 'owner';
        public function isActive(): bool { return true; }
        public function hasRole(string $role): bool {
            return $role === self::ROLE_PLATFORM_OWNER;
        }
    }
}
namespace App\Application\Commercial {
    class PlatformCommercialTrialCreator {
        public array $calls = [];
        public function create(string $tenantId, ?string $quoteId = null): array {
            $this->calls[] = [$tenantId, $quoteId];
            return [
                'status' => 'configured',
                'subscription' => ['state' => 'trialing', 'plan' => ['key' => 'business']],
            ];
        }
    }
}
namespace {
    require $argv[1];
    $creator = new \App\Application\Commercial\PlatformCommercialTrialCreator();
    $review = new \App\Application\Commercial\SelfServiceTrialReview($creator);
    $owner = new \App\Domain\Identity\Entity\User();
    $application = [
        'quote_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
        'email' => 'trial@example.test',
        'consent_recorded_at' => '2026-10-02T12:00:00.000000Z',
        'status' => 'pending_owner_review',
    ];
    $targetTenant = 'tenant-alpha';
    switch ($argv[2]) {
        case 'success': break;
        case 'missing_quote': unset($application['quote_id']); break;
        case 'empty_quote': $application['quote_id'] = ' '; break;
        case 'cross_tenant': $targetTenant = 'tenant-beta'; break;
        default: throw new \RuntimeException('Escenario desconocido');
    }
    try {
        $result = $review->review(
            $owner, 'tenant-alpha', $targetTenant, $application, 'approve'
        );
        echo json_encode(['ok' => true, 'result' => $result, 'calls' => $creator->calls],
            JSON_THROW_ON_ERROR);
    } catch (\Throwable $exception) {
        echo json_encode([
            'ok' => false, 'class' => $exception::class,
            'message' => $exception->getMessage(), 'calls' => $creator->calls,
        ], JSON_THROW_ON_ERROR);
    }
}
"""


class TrialCiCoverageTests(unittest.TestCase):
    def test_all_three_suites_reachable_from_backend_ci(self) -> None:
        workflow = CI.read_text(encoding="utf-8")
        backend = re.search(
            r"(?ms)^  backend-php:\n(?P<steps>.*?)(?=^  [\w-]+:\n|\Z)",
            workflow,
        )
        self.assertIsNotNone(backend, "Backend PHP/MariaDB no está definido")
        body = backend.group("steps")
        self.assertIn("if: needs.preflight.outputs.backend == 'true'", body)
        composer = body.find("-- composer install")
        trial = body.find(
            "python3 -m unittest discover -s tests -p "
            "'test_self_service_trial_*.py' -v"
        )
        self.assertGreaterEqual(composer, 0, "Composer debe instalarse en backend")
        self.assertGreater(trial, composer, "Las pruebas PHP deben correr tras Composer")
        self.assertEqual(
            sorted(path.name for path in (ROOT / "tests").glob(
                "test_self_service_trial_*.py"
            )),
            list(TRIAL_TESTS),
        )
        self.assertIn("name: Pruebas de contrato e integración", workflow)
        self.assertIn("name: Backend PHP / MariaDB", workflow)

    def test_release_version_is_not_pinned_by_trial_tests(self) -> None:
        for filename in TRIAL_TESTS:
            with self.subTest(test=filename):
                source = (ROOT / "tests" / filename).read_text(encoding="utf-8")
                self.assertNotIn("VERSION.read_text", source)
                self.assertNotRegex(
                    source, r"['\"]version['\"]\s*=>\s*['\"]0\.1\.\d+['\"]"
                )
                self.assertIn("subprocess.run(", source)
                self.assertIn("php", source)

    def _invoke(self, source_path: Path, scenario: str) -> dict:
        result = subprocess.run(
            ["php", "-r", PHP_REVIEW_HARNESS, "--", str(source_path), scenario],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def test_review_quote_identity_has_real_behavioral_assertions(self) -> None:
        # Ejecuta la clase PHP de producción con un creator spy, no un
        # grep/fixture textual ni una afirmación inventada de CI.
        expected = [["tenant-alpha", QUOTE_ID]]
        valid = self._invoke(REVIEW, "success")
        self.assertTrue(valid["ok"], valid)
        self.assertEqual(expected, valid["calls"])
        self.assertEqual(QUOTE_ID, valid["result"]["quote_id"])

        for scenario in ("missing_quote", "empty_quote", "cross_tenant"):
            with self.subTest(scenario=scenario):
                invalid = self._invoke(REVIEW, scenario)
                self.assertFalse(invalid["ok"], invalid)
                self.assertEqual([], invalid["calls"])

        # Prueba de mutación negativa: el mismo comportamiento de aprobación
        # dejaría de cumplir el spy si se omite o sustituye el segundo argumento.
        original = REVIEW.read_text(encoding="utf-8")
        call = "$targetTenantId,\n                $quoteId,"
        self.assertIn(call, original)
        mutants = {
            "omit_quote": original.replace(call, "$targetTenantId,", 1),
            "replace_quote": original.replace(
                call, "$targetTenantId,\n                '01BBBBBBBBBBBBBBBBBBBBBBBB',", 1
            ),
        }
        with tempfile.TemporaryDirectory() as temporary:
            for name, php in mutants.items():
                with self.subTest(mutation=name):
                    path = Path(temporary) / f"{name}.php"
                    path.write_text(php, encoding="utf-8")
                    result = self._invoke(path, "success")
                    self.assertTrue(result["ok"], result)
                    self.assertNotEqual(
                        expected, result["calls"],
                        "La mutación silenciosa debe ser detectable por el spy.",
                    )


if __name__ == "__main__":
    unittest.main()
