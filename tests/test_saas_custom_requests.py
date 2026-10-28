"""#1074 — proyección sintética de solicitudes Factory/custom; AC-01..04."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src/Application/Commercial/CustomRequestRegistry.php"


def event(action="CREATE", ref="one", n=1, tenant="alpha", company="one",
          role="tenant_admin", priority="medium", issue=None, kind="PRODUCT"):
    return {
        "event_ref": f"fixture:event/e{n}",
        "request_ref": f"fixture:request/{ref}",
        "tenant_ref": f"fixture:tenant/{tenant}",
        "company_ref": f"fixture:company/{company}",
        "actor_ref": "fixture:actor/tester",
        "actor_role": role,
        "occurred_at": f"2026-10-27T00:00:{n:02}Z",
        "action": action,
        "request_type": kind,
        "source": "FACTORY",
        "priority": priority,
        "issue_ref": issue,
    }


def fixture(tenant="alpha", company="one"):
    return {
        "classification": "synthetic",
        "tenant_ref": f"fixture:tenant/{tenant}",
        "company_ref": f"fixture:company/{company}",
        "events": [event(tenant=tenant, company=company)],
    }


def evaluate(data):
    source = r"""
require $argv[1];
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(\App\Application\Commercial\CustomRequestRegistry::evaluate($input), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", source,
         str(PHP), json.dumps(data)],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class SaasTests(unittest.TestCase):
    def assert_closed(self, data):
        observed = evaluate(data)
        self.assertEqual("unavailable", observed["status"])
        self.assertIsNone(observed["requests"])
        self.assertEqual([], observed["audit"])
        self.assertFalse(observed["can_charge"])
        self.assertFalse(observed["persisted"])
        return observed

    def test_aislamiento_por_empresa(self):
        a, b = fixture("alpha", "one"), fixture("beta", "two")
        x, y = evaluate(a), evaluate(b)
        self.assertEqual("valid", x["status"])
        self.assertEqual("valid", y["status"])
        self.assertEqual("fixture:tenant/alpha", next(iter(x["requests"].values()))["tenant_ref"])
        self.assertEqual("fixture:tenant/beta", next(iter(y["requests"].values()))["tenant_ref"])
        for field in ("tenant_ref", "company_ref"):
            mixed = copy.deepcopy(a)
            mixed["events"][0][field] = b["events"][0][field]
            with self.subTest(field=field):
                self.assert_closed(mixed)
        # Otras empresas no pueden añadir ni siquiera un evento secundario.
        mixed = copy.deepcopy(a)
        mixed["events"].append(event("TRIAGE", n=2, tenant="beta", company="two"))
        self.assert_closed(mixed)

    def test_auditoria_y_permisos(self):
        data = fixture()
        data["events"] += [
            event("TRIAGE", n=2),
            event("PRIORITIZE", n=3, role="platform_admin", priority="high"),
            event("LINK", n=4, role="platform_admin", priority="high", issue="fixture:issue/forty"),
        ]
        out = evaluate(data)
        self.assertEqual("valid", out["status"])
        self.assertEqual("LINKED", out["requests"]["fixture:request/one"]["status"])
        self.assertEqual("fixture:issue/forty", out["requests"]["fixture:request/one"]["issue_ref"])
        self.assertEqual(4, len(out["audit"]))
        self.assertEqual(["CREATE", "TRIAGE", "PRIORITIZE", "LINK"], [e["action"] for e in out["audit"]])
        self.assertEqual("fixture:actor/tester", out["audit"][3]["actor_ref"])
        self.assertFalse(out["can_charge"])
        self.assertFalse(out["persisted"])
        for forbidden in ("viewer", "customer", "", None):
            bad = fixture()
            bad["events"][0]["actor_role"] = forbidden
            self.assert_closed(bad)
        for action in ("PRIORITIZE", "LINK", "REJECT"):
            bad = fixture()
            bad["events"].append(event("TRIAGE", n=2))
            bad["events"].append(event(action, n=3, role="tenant_admin",
                                       issue="fixture:issue/forty" if action == "LINK" else None))
            self.assert_closed(bad)
        bad = fixture()
        bad["events"].append(event("LINK", n=2, role="platform_admin", issue="fixture:issue/forty"))
        self.assert_closed(bad)

    def test_sin_datos_reales(self):
        for change in (
            ("classification", "production"),
            ("tenant_ref", "900123456"),
            ("company_ref", "cliente@gmail.com"),
        ):
            bad = fixture()
            bad[change[0]] = change[1]
            with self.subTest(change=change):
                self.assert_closed(bad)
        for field, bad_value in (
            ("actor_ref", "Juan Pérez"),
            ("request_ref", "customer@example.com"),
            ("issue_ref", "https://github.com/pl0n3r/Condor/issues/1"),
            ("request_type", "custom: direccion 123"),
            ("source", "FACTORY con teléfono 3001234567"),
        ):
            bad = fixture()
            bad["events"][0][field] = bad_value
            out = self.assert_closed(bad)
            self.assertNotIn(str(bad_value), json.dumps(out))
        note = fixture()
        note["events"][0]["notes"] = "customer@example.com"
        self.assert_closed(note)

    def test_fail_closed(self):
        bads = []
        empty = fixture(); empty["events"] = []; bads.append(empty)
        incomplete = fixture(); del incomplete["events"][0]["occurred_at"]; bads.append(incomplete)
        extra = fixture(); extra["events"][0]["amount"] = 100000; bads.append(extra)
        duplicate = fixture(); duplicate["events"].append(copy.deepcopy(duplicate["events"][0])); bads.append(duplicate)
        missing_create = fixture(); missing_create["events"] = [event("TRIAGE")]; bads.append(missing_create)
        wrong_order = fixture(); wrong_order["events"].append(event("TRIAGE", n=1)); bads.append(wrong_order)
        invalid_date = fixture(); invalid_date["events"][0]["occurred_at"] = "2026-02-31T00:00:01Z"; bads.append(invalid_date)
        missing_link = fixture(); missing_link["events"] += [event("TRIAGE", n=2), event("LINK", n=3, role="platform_admin")]; bads.append(missing_link)
        repeated = fixture(); repeated["events"].append(event("CREATE", n=2)); bads.append(repeated)
        changed_type = fixture(); changed_type["events"].append(event("TRIAGE", n=2, kind="CUSTOM")); bads.append(changed_type)
        for num, bad in enumerate(bads):
            with self.subTest(case=num):
                self.assert_closed(bad)
        good = fixture()
        good["events"].append(event("TRIAGE", n=2))
        good["events"].append(event("REJECT", n=3, role="platform_admin"))
        out = evaluate(good)
        self.assertEqual("valid", out["status"])
        self.assertEqual("REJECTED", out["requests"]["fixture:request/one"]["status"])


if __name__ == "__main__":
    unittest.main()
