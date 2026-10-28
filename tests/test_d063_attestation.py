"""AC-01 Condor #944: atestación factual D-063, estricta y offline.

Procedencia humana verificada antes de materializar esta hoja:
- OWNER #924 /decidir A: issuecomment-6417972614
- Coordinación journal v2/A: issuecomment-6417986434
La prueba NO consulta GitHub ni infiere hechos de phase/CI; solo valida la
declaración versionada del dueño. No autoriza RRHH, datos reales ni live.
"""

from __future__ import annotations

import copy
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "datos.yml"
FACTS = ("nothing_live", "no_real_customer_data")
ROOT_KEYS = frozenset({
    "version", "project", "phase", "controller", "treatments",
    "d063_attestation",
})
TREATMENTS = frozenset({
    "account_identity", "account_invitation", "account_password",
    "audit_events", "condor_password_reset",
    "contract_synthetic_records",
    "controlbot_staff_operations", "customer_contact",
    "diagnostic_shares", "error_incidents", "notification_delivery",
    "notification_preferences", "self_service_trial_application",
    "workflow_definitions", "workflow_executions",
    "payroll_synthetic_records", "social_security_synthetic_records",
    "human_resources_synthetic_records",
})


def build_only_attestation(document: object) -> bool:
    """Solo true booleano explícito para AMBOS hechos en construcción."""
    if not isinstance(document, dict) or set(document) != ROOT_KEYS:
        return False
    if document.get("version") != 1 or document.get("project") != "pl0n3r/Condor":
        return False
    if document.get("phase") != "construccion":
        return False
    facts = document.get("d063_attestation")
    if not isinstance(facts, dict) or set(facts) != set(FACTS):
        return False
    return all(type(facts[name]) is bool and facts[name] is True for name in FACTS)


class D063AttestationTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        data = json.loads(DATA.read_text(encoding="utf-8"))
        self.assertTrue(build_only_attestation(data))
        self.assertEqual(
            {"nothing_live": True, "no_real_customer_data": True},
            data["d063_attestation"],
        )
        self.assertEqual("construccion", data["phase"])
        self.assertEqual(TREATMENTS, frozenset(t["id"] for t in data["treatments"]))
        self.assertEqual(len(TREATMENTS), len(data["treatments"]))
        self.assertEqual(
            {"[COMPLETAR POR EL DUEÑO]"},
            set(data["controller"].values()),
        )
        for treatment in data["treatments"]:
            self.assertEqual("review_required", treatment["basis"])
            self.assertEqual("review_required", treatment["consent"])

        payroll = next(t for t in data["treatments"] if t["id"] == "payroll_synthetic_records")
        self.assertEqual("usage", payroll["category"])
        self.assertEqual("review_required", payroll["purpose"])
        self.assertEqual("review_required", payroll["retention"])
        self.assertEqual([], payroll["providers"])

        # No hay fallback a phase=construccion ni inferencia de autorización.
        cases = {
            "missing_attestation": lambda d: d.pop("d063_attestation"),
            "false_live": lambda d: d["d063_attestation"].update(nothing_live=False),
            "false_data": lambda d: d["d063_attestation"].update(no_real_customer_data=False),
            "null_live": lambda d: d["d063_attestation"].update(nothing_live=None),
            "null_data": lambda d: d["d063_attestation"].update(no_real_customer_data=None),
            "string_live": lambda d: d["d063_attestation"].update(nothing_live="true"),
            "string_data": lambda d: d["d063_attestation"].update(no_real_customer_data="true"),
            "integer_live": lambda d: d["d063_attestation"].update(nothing_live=1),
            "missing_flag": lambda d: d["d063_attestation"].pop("no_real_customer_data"),
            "extra_flag": lambda d: d["d063_attestation"].update(legally_approved=True),
            "live_phase": lambda d: d.update(phase="live"),
            "unknown_phase": lambda d: d.update(phase=None),
            "extra_root": lambda d: d.update(approved=True),
        }
        for name, mutation in cases.items():
            with self.subTest(negative=name):
                invalid = copy.deepcopy(data)
                mutation(invalid)
                self.assertFalse(build_only_attestation(invalid))

        # La atestación factual NO convierte documentación en aprobación legal.
        notice = (ROOT / "docs/privacidad/aviso-privacidad.md").read_text(encoding="utf-8")
        self.assertIn("revisión jurídica requerida", notice.lower())
        self.assertIn("[COMPLETAR POR EL DUEÑO]", notice)
        self.assertNotIn("d063_attestation", notice)


if __name__ == "__main__":
    unittest.main()
