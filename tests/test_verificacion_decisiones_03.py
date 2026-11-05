#!/usr/bin/env python3
"""#1546: testigos deterministas, sin falsificar cobertura ni ejecutar producción."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/verificacion/decisiones-03.md"
WITNESSES = [
    ("D-040-S07", "config/packages/framework.yaml", "secret: '%env(APP_SECRET)%'", "tests/test_salida_secretos_accesos.py", "def test_sin_secretos_ni_datos_reales"),
    ("D-040-S09", "src/Infrastructure/Observability/SentryEventSanitizer.php", "public const REDACTED = '[redacted]';", "tests/test_ci_sentry_privacy_contract.py", "def test_sensitive_exception_and_event_message_are_denied"),
    ("D-040-S10", "tests/test_billing_audit_envelope.py", "def test_pii_secrets_payment_data_and_free_form_payload_fail_closed", "tests/test_billing_audit_envelope.py", "def test_envelope_keeps_only_minimized_canonical_billing_evidence"),
    ("D-040-S11", "scripts/backup-persistent-files.py", "SYNTHETIC_MARKER", "tests/test_backup_persistent_files.py", "def test_round_trip_verifies_bytes_nested_paths_and_private_permissions"),
    ("D-040-S12", "scripts/simular_reversion_apagado.py", "PASSED_SYNTHETIC_ONLY", "tests/test_ci_salida_reversion_simulacro.py", "def test_apagado_y_recuperacion_sinteticos"),
    ("D-040-S13", ".github/dependabot.yml", "package-ecosystem:", "tests/test_dependabot_policy.py", "def test_composer_major_updates_are_ignored"),
    ("D-040-S14", "scripts/post-deploy.sh", "CONDOR_AUTO_MIGRATE", "tests/test_post_deploy.py", "def test_construction_blocks_destructive_sql_after_punctuation"),
    ("D-040-S15", "tests/php/Security/NewModulesTenantIsolationTest.php", "testPhotoBankRejectsCrossTenantReadAndWriteBeforePersistence", "tests/test_new_modules_tenant_isolation.py", "def test_acceptance"),
    ("D-040-S19", "src/Application/Inventory/StockTransferService.php", "class StockTransferService", "tests/test_stock_transfers_api.py", "def test_acceptance"),
    ("D-040-S20", "config/packages/security.yaml", "enable_csrf: true", "tests/test_ci_security_http_session.py", "def test_session_csrf_acceptance_has_phpunit_backend"),
    ("D-041", "frontend/admin/AdminShell.tsx", "navItems: readonly AdminNavItem[]", None, None),
    ("D-041-S02", "frontend/admin/admin.css", "gap: var(--space-4);", None, None),
    ("D-041-S03", "frontend/admin/admin.css", "grid-template-columns: 1fr;", None, None),
    ("D-041-S05", "frontend/admin/AdminShell.tsx", "aria-current={item.current ? 'page' : undefined}", None, None),
    ("D-041-S06", "frontend/admin/admin.css", "min-height: 44px;", None, None),
]
GAPS = {
    "D-040-S08": 1603,
    "D-040-S16": 1603,
    "D-040-S17": 1604,
    "D-040-S18": 1604,
    "D-041-S04": 1605,
}
ROWS = (
    "D-040-S07",
    "D-040-S08",
    "D-040-S09",
    "D-040-S10",
    "D-040-S11",
    "D-040-S12",
    "D-040-S13",
    "D-040-S14",
    "D-040-S15",
    "D-040-S16",
    "D-040-S17",
    "D-040-S18",
    "D-040-S19",
    "D-040-S20",
    "D-041",
    "D-041-S02",
    "D-041-S03",
    "D-041-S04",
    "D-041-S05",
    "D-041-S06",
)


def has_witness(content: str, exact: str) -> bool:
    """No aceptar alusiones difusas como equivalentes a guardas ejecutables."""
    return exact in content


class Decisiones03Test(unittest.TestCase):
    def test_veinte_filas_con_identidad_unica(self):
        all_ids = [w[0] for w in WITNESSES] + list(GAPS)
        self.assertEqual(len(all_ids), 20)
        self.assertEqual(len(set(all_ids)), 20)
        self.assertEqual(set(all_ids), set(ROWS))
        text = DOC.read_text(encoding="utf-8")
        for identifier in ROWS:
            with self.subTest(identifier=identifier):
                self.assertEqual(text.count("| `" + identifier + "` ·"), 1)

    def test_fuentes_y_regresiones_existentes(self):
        for identifier, path, anchor, test_path, method in WITNESSES:
            with self.subTest(identifier=identifier):
                source = ROOT / path
                self.assertTrue(source.is_file(), path)
                self.assertTrue(has_witness(source.read_text(encoding="utf-8"), anchor),
                                identifier + ": falta la guarda exacta " + anchor)
                if test_path:
                    executable = ROOT / test_path
                    self.assertTrue(executable.is_file(), test_path)
                    self.assertTrue(has_witness(executable.read_text(encoding="utf-8"), method),
                                    identifier + ": falta regresión " + method)

    def test_eliminar_guarda_no_pasa_aceptacion(self):
        for identifier, path, anchor, _, _ in WITNESSES:
            with self.subTest(identifier=identifier):
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertTrue(has_witness(source, anchor))
                mutated = source.replace(anchor, "CONTROL_ELIMINADO")
                self.assertFalse(has_witness(mutated, anchor))

    def test_brechas_con_hojas_hijas_sin_sobredeclaracion(self):
        doc = DOC.read_text(encoding="utf-8")
        self.assertIn("NO_VERIFICABLE", doc)
        self.assertIn("No acredita producción", doc)
        self.assertIn("no equivale a CUBIERTO", doc)
        for identifier, issue in GAPS.items():
            with self.subTest(identifier=identifier):
                line = next(s for s in doc.splitlines()
                            if s.startswith("| `" + identifier + "` ·"))
                self.assertIn("| BRECHA |", line)
                self.assertIn("issues/" + str(issue), line)
                self.assertNotIn("| CUBIERTO |", line)

    def test_testigos_declaran_alcance_limitado(self):
        doc = DOC.read_text(encoding="utf-8")
        for identifier, *_ in WITNESSES:
            with self.subTest(identifier=identifier):
                line = next(s for s in doc.splitlines()
                            if s.startswith("| `" + identifier + "` ·"))
                self.assertIn("| TESTIGO_ACOTADO |", line)


if __name__ == "__main__":
    unittest.main()
