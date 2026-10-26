#!/usr/bin/env python3
"""AC-01 Condor #939: actualización de duración aislada y sin inventario."""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/ServiceCatalogController.php"
PRODUCT = ROOT / "src/Domain/Catalog/Entity/Product.php"


class ServiceDurationUpdateApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se necesita PHP CLI para validar el controlador.")
        for path in (CONTROLLER, PRODUCT):
            self.assertTrue(path.is_file(), str(path))
            run = subprocess.run(
                [php, "-l", str(path)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=20,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)

        controller = CONTROLLER.read_text(encoding="utf-8")
        product = PRODUCT.read_text(encoding="utf-8")
        self.assertIn(
            "'/api/v1/branches/{branchId}/catalog/services/{serviceId}'",
            controller,
        )
        self.assertRegex(
            controller,
            r"api_catalog_service_duration_update'[\s\S]{0,120}"
            r"methods:\s*\['PATCH'\]",
        )
        section = controller.split("public function updateDuration(", 1)[1].split(
            "private function requiredText", 1
        )[0]
        for expected in (
            "$this->requireCsrf($request)",
            "'catalog.update'",
            "$this->authorizedBranchScope(",
            "$this->payload($request, ['duration_minutes'])",
            "array_key_exists('duration_minutes', $payload)",
            "$duration !== null && !is_int($duration)",
            "'tenant' => $tenant",
            "'itemKind' => Product::KIND_SERVICE",
            "'active' => true",
            "if (!$service instanceof Product)",
            "$service->updateServiceDuration($duration)",
            "'catalog.service.duration_updated'",
            "$this->entityManager->flush()",
            "'duration_minutes' => $service->durationMinutes()",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, section)

        self.assertNotIn("stock", section.lower())
        self.assertNotIn("inventario", section.lower())
        self.assertIn("function updateServiceDuration(?int $minutes)", product)
        self.assertIn("if (!$this->isService())", product)
        self.assertIn("if ($minutes !== null && ($minutes < 1 || $minutes > 525600))", product)

    def test_no_tenant_or_kind_override_and_no_unsafe_fields(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        section = controller.split("public function updateDuration(", 1)[1].split(
            "private function requiredText", 1
        )[0]
        self.assertNotIn("'tenant_id'", section)
        self.assertNotIn("'allow_backorder'", section)
        self.assertNotIn("setBackorderAllowed", section)
        self.assertIn("UnprocessableEntityHttpException", section)
        self.assertIn("NotFoundHttpException", section)
        self.assertFalse(re.search(r"\bDELETE\b|\bDROP\b", section))


if __name__ == "__main__":
    unittest.main()
