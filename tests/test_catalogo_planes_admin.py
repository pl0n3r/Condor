#!/usr/bin/env python3
"""Contratos de regresión del catálogo administrativo de planes (#1448).

Solo utiliza fixtures conceptuales y fuentes locales. No inicia pagos ni
requiere credenciales de producción.
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
AUDIT = ROOT / "src/Domain/Audit/Entity/PlatformAuditEvent.php"
DOCUMENT = ROOT / "docs/catalogo-planes.md"


class CatalogoPlanesAdminTests(unittest.TestCase):
    def source(self, path: Path) -> str:
        self.assertTrue(path.is_file(), f"Falta implementación: {path.relative_to(ROOT)}")
        return path.read_text(encoding="utf-8")

    def test_criterio_principal(self) -> None:
        """Editor propietario: versiones, límites, funciones, historial, tarifa vacía."""
        controller = self.source(CONTROLLER)
        self.assertIn("/adminpl0n3r/planes", controller)
        self.assertIn("/adminpl0n3r/api/plans/{key}", controller)
        self.assertIn("platformOwner()", controller)
        self.assertIn("requireManagementCsrf(", controller)
        self.assertIn("expected_version", controller)
        self.assertIn("wrapInTransaction(", controller)
        self.assertIn("PESSIMISTIC_WRITE", controller)
        self.assertIn("PlatformAuditEvent", controller)
        self.assertIn("['old' => $before, 'new' => $after]", controller)
        self.assertIn("new PlanVersion(", controller)
        self.assertRegex(controller, r"null,\s*null,\s*true,\s*\$payload\['limits'\]")
        self.assertIn("CommercialCatalogReader", controller)
        self.assertIn("rename(", self.source(IDENTITY))
        self.assertIn("closeAt(", self.source(VERSION))
        self.assertIn("'limits' => $version->limits()", self.source(READER))

    def test_historical_contract_rejects_forged_configured_limits(self) -> None:
        resolver = self.source(ROOT / "src/Application/Commercial/EntitlementResolver.php")
        self.assertIn(
            "$context->configuredLimits() !== $configuration->quantities()",
            resolver,
        )

    def test_seed_preserves_history_but_current_rates_are_empty(self) -> None:
        seeder = self.source(SEEDER)
        # Los contratos que consultan la fecha histórica conservan sus cifras
        # anteriores: una nueva decisión no puede falsificar el pasado.
        self.assertIn("'basic' => [79900, 799000, false", seeder)
        self.assertIn("2026-09-27T00:00:00Z", seeder)
        self.assertIn("2026-10-30T00:00:00Z", seeder)
        self.assertIn("$historical->closeAt($withoutPricesFrom)", seeder)
        self.assertRegex(
            seeder,
            r"\$plans\[\$key\],\s*2,\s*null,\s*null,\s*true,\s*\$limits",
        )
        self.assertIn("'version' => $number", seeder)
        self.assertIn("$historical->capabilities()", seeder)

    def test_owner_csrf_conflict_and_invalid_input_fail_closed(self) -> None:
        controller = self.source(CONTROLLER)
        for required in (
            "platformOwner()", "requireManagementCsrf(", "isCsrfTokenValid(",
            "expected_version", "Configuración de plan inválida",
            "Funcionalidad duplicada", "Funcionalidad del catálogo desconocida",
            "UnprocessableEntityHttpException",
            "JsonResponse::HTTP_UNPROCESSABLE_ENTITY",
            "'capabilities', 'expected_version', 'limits', 'name'",
            "PESSIMISTIC_WRITE",
        ):
            with self.subTest(required=required):
                self.assertIn(required, controller)
        for forbidden in ("BillingAdapter", "PaymentGateway", "charge(", "invoice("):
            self.assertNotIn(forbidden, controller)

    def test_audit_is_structured_and_reader_remains_canonical(self) -> None:
        controller = self.source(CONTROLLER)
        audit = self.source(AUDIT)
        reader = self.source(READER)
        for field in ("'name'", "'limits'", "'capabilities'", "'version'"):
            self.assertIn(field, controller)
        self.assertIn("'commercial.plan.updated'", controller)
        self.assertIn("'old' => $before", controller)
        self.assertIn("'new' => $after", controller)
        self.assertIn("actorUserId", audit)
        self.assertIn("createdAt", audit)
        self.assertIn("DateTimeZone('UTC')", audit)
        self.assertIn("PlanVersionTimeline", reader)
        self.assertIn("monthlyAmount()", reader)
        self.assertIn("quoteRequired()", reader)

    def test_contract_documents_synthetic_scope_and_reversal(self) -> None:
        document = self.source(DOCUMENT)
        for value in (
            "CommercialCatalogReader",
            "PlatformAuditEvent",
            "sin tarifa definida",
            "datos",
            "Fuera de alcance",
            "Revertir",
            "2026-09-27",
            "2026-10-30",
        ):
            self.assertIn(value, document)


if __name__ == "__main__":
    unittest.main()
