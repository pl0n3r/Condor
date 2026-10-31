#!/usr/bin/env python3
"""Contratos de regresión del catálogo administrativo de planes (#1448).

Estas pruebas no crean tarifas, cuentas ni pagos y no suplantan el CI de PHP.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PlatformPlanCatalogController.php"
IDENTITY = ROOT / "src/Domain/Commercial/Entity/CommercialIdentity.php"
VERSION = ROOT / "src/Domain/Commercial/Entity/PlanVersion.php"
SEEDER = ROOT / "src/Application/Commercial/CommercialCatalogSeeder.php"
READER = ROOT / "src/Application/Commercial/CommercialCatalogReader.php"
DOCUMENT = ROOT / "docs/catalogo-planes.md"


class CatalogoPlanesAdminTests(unittest.TestCase):
    def source(self, path: Path) -> str:
        self.assertTrue(path.is_file(), f"Falta implementación: {path.relative_to(ROOT)}")
        return path.read_text(encoding="utf-8")

    def test_criterio_principal(self) -> None:
        """El admin conserva un catálogo versionado, auditable y sin tarifas."""
        controller = self.source(CONTROLLER)
        self.assertIn("/adminpl0n3r/planes", controller)
        self.assertIn("/adminpl0n3r/api/plans/{key}", controller)
        self.assertIn("platformOwner()", controller)
        self.assertIn("requireManagementCsrf(", controller)
        self.assertIn("expected_version", controller)
        self.assertIn("wrapInTransaction(", controller)
        self.assertIn("PlatformAuditEvent", controller)
        self.assertIn("old", controller)
        self.assertIn("new", controller)
        self.assertIn("new PlanVersion(", controller)
        self.assertIn("null, null, true", controller.replace("\n", " "))
        self.assertIn("CommercialCatalogReader", controller)
        self.assertIn("rename(", self.source(IDENTITY))
        self.assertIn("closeAt(", self.source(VERSION))
        self.assertIn("'limits' => $version->limits()", self.source(READER))

    def test_plan_defaults_have_no_unapproved_rates(self) -> None:
        seeder = self.source(SEEDER)
        definitions = seeder.split("$definitions = [", 1)[1].split("];", 1)[0]
        # La semilla de planes no presupone cifras comerciales.
        for key in ("basic", "business", "pro", "enterprise"):
            self.assertRegex(
                definitions,
                rf"'{key}'\s*=>\s*\[null,\s*null,\s*true",
            )
        self.assertNotRegex(
            definitions, r"'(?:basic|business|pro|enterprise)'\s*=>\s*\[\d",
        )

    def test_no_billing_or_untrusted_write_surface(self) -> None:
        controller = self.source(CONTROLLER)
        self.assertIn("JsonResponse::HTTP_UNPROCESSABLE_ENTITY", controller)
        self.assertIn("capabilities", controller)
        self.assertIn("limits", controller)
        self.assertNotIn("BillingAdapter", controller)
        self.assertNotIn("PaymentGateway", controller)
        self.assertNotIn("monthly_amount',", controller)  # no edición de tarifas
        self.assertIn("CSRF", controller)

    def test_contract_explicitly_preserves_canonical_reader(self) -> None:
        doc = self.source(DOCUMENT)
        self.assertIn("CommercialCatalogReader", doc)
        self.assertIn("PlatformAuditEvent", doc)
        self.assertIn("sin tarifa definida", doc)
        self.assertIn("datos", doc.lower())
        self.assertIn("Fuera de alcance", doc)


if __name__ == "__main__":
    unittest.main()
