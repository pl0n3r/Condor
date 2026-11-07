"""Contract of #1823: synthetic offline threshold evaluation, no real alert."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/finops_1493_offline.py"
DOC = ROOT / "docs/verificacion/finops-1493-simulacion.md"
spec = importlib.util.spec_from_file_location("finops_1493_offline", SCRIPT)
assert spec and spec.loader
finops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(finops)


def fixture(provider="SIM_ALPHA", currency="COP", limit=10000, before=4900, after=5000):
    return {"synthetic": True, "lines": [{
        "provider": provider, "currency": currency,
        "limit_minor": limit, "previous_minor": before, "current_minor": after,
    }]}


class FinOpsOfflineTests(unittest.TestCase):
    def test_exact_threshold_crossings(self) -> None:
        samples = [
            (0, 4900, []), (4900, 5000, [50]), (5000, 7900, []),
            (7900, 8000, [80]), (8000, 9900, []), (9900, 10000, [100]),
            (10000, 10100, []), (0, 10100, [50, 80, 100]),
        ]
        for before, after, expected in samples:
            with self.subTest(before=before, after=after):
                self.assertEqual(finops.crossings(before, after, 10000), expected)
                report = finops.simulate(fixture(before=before, after=after))
                self.assertEqual([x["threshold_percent"] for x in report["alerts"]
                                  if x["scope"] == "provider"], expected)
                self.assertEqual([x["threshold_percent"] for x in report["alerts"]
                                  if x["scope"] == "aggregate"], expected)
                self.assertTrue(all(x["delivery"] == "NOT_SENT" for x in report["alerts"]))

    def test_currency_and_budget_fail_closed(self) -> None:
        invalid = [
            {"synthetic": False, "lines": fixture()["lines"]},
            {"synthetic": True, "lines": []},
            fixture(provider="REAL_PROVIDER"), fixture(provider="SIM_ALPHA@example.org"),
            fixture(currency="EUR"), fixture(limit=0), fixture(limit=True),
            fixture(before=-1), fixture(before=200, after=100), fixture(after=3.5),
            dict(fixture(), recipient="synthetic@example.org"),
            {"synthetic": True, "lines": fixture()["lines"] * 2},
            {"synthetic": True, "lines": [fixture()["lines"][0], fixture(provider="SIM_BETA", currency="USD")["lines"][0]]},
            {"synthetic": True, "lines": [dict(fixture()["lines"][0], token="secret")]},
        ]
        for index, bad in enumerate(invalid):
            with self.subTest(index=index):
                with self.assertRaises(finops.SyntheticInputError):
                    finops.simulate(bad)

    def test_no_provider_accounts_or_real_alerts(self) -> None:
        report = finops.simulate(fixture(before=4900, after=10100))
        self.assertEqual(report["mode"], "SYNTHETIC_ONLY")
        self.assertFalse(report["budget_authorized"])
        self.assertEqual(report["operation"], "NO_APLICAR_CAMBIOS")
        self.assertEqual(report["purchasing"], "NO_COMPRAR")
        self.assertEqual(report["delivery"], "NOT_SENT")
        self.assertFalse(any(key in json.dumps(report).lower() for key in ("email", "token", "webhook", "hostinger")))
        code = SCRIPT.read_text(encoding="utf-8")
        for forbidden in ("import requests", "import socket", "import smtplib", "import urllib", "import subprocess", "import boto3"):
            self.assertNotIn(forbidden, code)
        completed = subprocess.run(
            [sys.executable, str(SCRIPT), "--simulate"],
            input=json.dumps(fixture()), text=True, capture_output=True, check=False, timeout=8,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(json.loads(completed.stdout)["delivery"], "NOT_SENT")
        denied = subprocess.run(
            [sys.executable, str(SCRIPT), "--send"],
            input="{}", text=True, capture_output=True, check=False, timeout=8,
        )
        self.assertNotEqual(denied.returncode, 0)
        self.assertFalse(denied.stdout)

    def test_documentation_and_currency_isolation(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        for required in (
            "49/50/79/80/99/100/101", "NO_COMPRAR", "NO_APLICAR_CAMBIOS", "NOT_SENT",
            "DEL_DUEÑO", "50/80/100", "#1493", "COP", "USD", "NO_AUTORIZA_GO_LIVE",
            "prueba real de entrega", "backup", "reversión"
        ):
            with self.subTest(required=required):
                self.assertIn(required, text)
        only_dollars = finops.simulate(fixture(provider="SIM_BETA", currency="USD"))
        self.assertEqual(only_dollars["currency"], "USD")
        self.assertEqual(only_dollars["alerts"][0]["currency"], "USD")


if __name__ == "__main__":
    unittest.main()
