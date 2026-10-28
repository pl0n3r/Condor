"""Condor #1066: execute the real TypeScript parser with adversarial fixtures.

All tenants, amounts and audit receipts are synthetic. No network, DB or payments.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "frontend/admin/PlatformSaasDashboardPanel.tsx"

RUNNER = r"""
const fs = require('fs');
const vm = require('vm');
const ts = require('typescript');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const source = fs.readFileSync(process.argv[1], 'utf8');
const result = ts.transpileModule(source, {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022, esModuleInterop: true },
  reportDiagnostics: true,
});
if (result.diagnostics?.some(d => d.category === ts.DiagnosticCategory.Error)) process.exit(11);
const m = { exports: {} };
const react = { useEffect(){}, useState(){} };
const requireStub = (id) => {
  if (id === 'react') return react;
  if (id === 'react/jsx-runtime') return { jsx(){}, jsxs(){} };
  throw Error('external module denied: ' + id);
};
vm.runInNewContext(result.outputText, { module: m, exports: m.exports,
  require: requireStub, Intl, AbortController, Number, Object, String, Promise }, { timeout: 1000 });
const results = input.map(row => m.exports.parseSaasDashboardPayload(
  row.payload, row.tenant, row.owner, row.synthetic));
process.stdout.write(JSON.stringify(results));
"""

SOURCE_IDS = {
    "snapshot": "App\\Application\\Commercial\\PlatformCommercialMetrics",
    "movements": "App\\Application\\Commercial\\SaasRevenueMovements",
    "retention": "App\\Application\\Commercial\\SaasRetentionMetrics",
}


def good(tenant="synthetic-tenant-alpha"):
    return {
        "tenant_id": tenant, "synthetic": True, "owner_scope": "platform_owner",
        "status": "ready", "projection": {
            "status": "valid", "reason": None, "currency": "COP",
            "mrr_minor": 260000, "arr_minor": 3120000,
            "active_customers": 2, "trials": 1,
            "arpa_minor_exact": {"numerator": 130000, "denominator": 1},
            "new_mrr_minor": 200000, "expansion_mrr_minor": 60000,
            "contraction_mrr_minor": 0, "churned_mrr_minor": 0,
            "nrr_percent_exact": {"numerator": 130, "denominator": 1},
            "audit_count": 2, "source": dict(SOURCE_IDS),
        },
    }


def entry(payload, tenant="synthetic-tenant-alpha", owner=True, synthetic=True):
    return {"payload": payload, "tenant": tenant, "owner": owner, "synthetic": synthetic}


def evaluate(*entries):
    proc = subprocess.run(["node", "-e", RUNNER, str(SOURCE)], cwd=ROOT,
        input=json.dumps(entries), capture_output=True, text=True, timeout=15, check=False)
    if proc.returncode:
        raise AssertionError(f"typescript runtime failed {proc.returncode}: {proc.stderr}")
    return json.loads(proc.stdout)


class SaasTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        own = good()
        foreign = good("synthetic-tenant-beta")
        result = evaluate(entry(own), entry(foreign), entry(foreign, tenant="synthetic-tenant-beta"),
            entry(own, owner=False), entry(own, synthetic=False))
        self.assertEqual(["ready", "unknown", "ready", "unknown", "unknown"],
            [row["status"] for row in result])
        self.assertEqual(260000, result[0]["data"]["mrr_minor"])

    def test_auditoria_y_permisos(self):
        invalid_audit = good(); invalid_audit["projection"]["audit_count"] = -1
        invalid_source = good(); invalid_source["projection"]["source"]["snapshot"] = "other"
        missing_audit = good(); del missing_audit["projection"]["audit_count"]
        missing_scope = good(); missing_scope["owner_scope"] = "tenant_admin"
        found = evaluate(entry(good()), entry(invalid_audit), entry(invalid_source),
            entry(missing_audit), entry(missing_scope))
        self.assertEqual(["ready", "unknown", "unknown", "unknown", "unknown"],
            [x["status"] for x in found])
        self.assertEqual(2, found[0]["data"]["audit_count"])
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("loadMetrics(tenantId, controller.signal)", source)
        self.assertNotIn("fetch(", source)
        self.assertNotIn("method: 'POST'", source)
        self.assertNotIn("localStorage", source)

    def test_sin_datos_reales(self):
        personal = good(); personal["projection"]["customer_email"] = "person@example.test"
        payment = good(); payment["bank_account"] = "synthetic-unknown"
        tenant = good("real-company-001")
        bad_currency = good(); bad_currency["projection"]["currency"] = "COP-REAL"
        outcome = evaluate(entry(personal), entry(payment), entry(tenant, tenant="real-company-001"),
            entry(bad_currency))
        self.assertEqual(["unknown"] * 4, [x["status"] for x in outcome])
        source = SOURCE.read_text(encoding="utf-8")
        self.assertNotIn("customer_email", source)
        self.assertNotIn("bank_account", source)
        self.assertIn("onlyKeys(p, keys)", source)

    def test_fail_closed(self):
        negative = good(); negative["projection"]["mrr_minor"] = -1
        float_amount = good(); float_amount["projection"]["arr_minor"] = 9.25
        ambiguous = good(); ambiguous["projection"]["nrr_percent_exact"]["denominator"] = 0
        no_arpa = good(); no_arpa["projection"]["arpa_minor_exact"] = None
        undefined = {"tenant_id": "synthetic-tenant-alpha", "owner_scope": "platform_owner",
            "synthetic": True, "status": "empty", "projection": None}
        invalid_empty = dict(undefined); invalid_empty["projection"] = {"unexpected": 1}
        responses = evaluate(entry(negative), entry(float_amount), entry(ambiguous),
            entry(no_arpa), entry(undefined), entry(invalid_empty), entry(None))
        self.assertEqual(["unknown", "unknown", "unknown", "unknown", "empty", "unknown", "unknown"],
            [row["status"] for row in responses])
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn("controller.abort()", source)
        self.assertIn("if (!controller.signal.aborted)", source)
        self.assertIn('role="alert"', source)
        self.assertIn("aria-live=", source)


if __name__ == "__main__":
    unittest.main()
