#!/usr/bin/env python3
"""M07 S01-S02 synthetic finance projections: exercise real PHP, not mocks."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Finance/FinanceLedgerReceivables.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"


def entry(**changes: object) -> dict:
    item = {
        "entry_ref": "fixture:finance-entry/i-1", "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY, "kind": "INGRESO", "amount_decimal": "0.10",
        "booked_on": "2026-10-01", "state": "BORRADOR",
    }
    item.update(changes)
    return item


def account(**changes: object) -> dict:
    item = {
        "account_ref": "fixture:finance-account/c-1", "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY, "direction": "COBRAR", "amount_decimal": "42.50",
        "booked_on": "2026-08-01", "due_on": "2026-09-26", "state": "PENDIENTE",
    }
    item.update(changes)
    return item


def ledger(**changes: object) -> dict:
    item = {
        "fixture_ref": "fixture:finance-ledger/l-1", "tenant_ref": TENANT,
        "legal_entity_ref": ENTITY, "as_of": "2026-10-27",
        "entries": [entry()], "accounts": [account()],
    }
    item.update(changes)
    return item


def project(data: dict | None = None, tenant: str = TENANT, entity: str = ENTITY) -> dict:
    script = r"""
require $argv[1];
use App\Application\Finance\FinanceLedgerReceivables;
$input = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(FinanceLedgerReceivables::project(
    $input['ledger'], $input['tenant'], $input['entity']
), JSON_THROW_ON_ERROR);
"""
    proc = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script, str(CLASS),
         json.dumps({"ledger": ledger() if data is None else data,
                     "tenant": tenant, "entity": entity})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(proc.stdout)


class FinanceLedgerReceivablesTests(unittest.TestCase):
    def assert_safe(self, result: dict, entries: int, accounts: int) -> None:
        self.assertEqual("REQUIERE_REVISION", result["state"])
        self.assertIs(result["requires_professional_review"], True)
        self.assertIs(result["synthetic_only"], True)
        self.assertEqual("COP", result["currency"])
        self.assertEqual(entries, len(result["entries"]))
        self.assertEqual(accounts, len(result["accounts"]))
        self.assertEqual(
            {"state", "synthetic_only", "requires_professional_review", "currency",
             "income_total_decimal", "expense_total_decimal", "receivable_total_decimal",
             "payable_total_decimal", "entries", "accounts"},
            set(result),
        )
        for forbidden in ("APROBADO", "PAGADO", "CERRADO", "EMAIL", "CEDULA"):
            self.assertNotIn(forbidden, json.dumps(result))

    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project())
        self.assert_safe(first, 1, 1)
        self.assertEqual("0.10", first["income_total_decimal"])
        self.assertEqual("42.50", first["receivable_total_decimal"])
        self.assertEqual(31, first["accounts"][0]["overdue_days"])
        self.assertEqual("VENCIDA_31_60", first["accounts"][0]["aging_band"])
        self.assertTrue(first["accounts"][0]["attention_candidate"])

        many = ledger(entries=[
            entry(entry_ref="fixture:finance-entry/i-2", amount_decimal="0.20"),
            entry(entry_ref="fixture:finance-entry/i-3", kind="EGRESO", amount_decimal="1.02"),
            entry(),
        ], accounts=[
            account(account_ref="fixture:finance-account/c-2", direction="PAGAR",
                    due_on="2026-12-01", amount_decimal="9.03"),
            account(),
        ])
        reverse = copy.deepcopy(many)
        reverse["entries"].reverse()
        reverse["accounts"].reverse()
        self.assertEqual(project(many), project(reverse))
        totals = project(many)
        self.assertEqual("0.30", totals["income_total_decimal"])
        self.assertEqual("1.02", totals["expense_total_decimal"])
        self.assertEqual("42.50", totals["receivable_total_decimal"])
        self.assertEqual("9.03", totals["payable_total_decimal"])
        self.assertEqual("NO_VENCIDA", totals["accounts"][1]["aging_band"])
        for due_on, band, days in (
            ("2026-10-27", "NO_VENCIDA", 0),
            ("2026-10-26", "VENCIDA_1_30", 1),
            ("2026-09-27", "VENCIDA_1_30", 30),
            ("2026-09-26", "VENCIDA_31_60", 31),
            ("2026-08-28", "VENCIDA_31_60", 60),
            ("2026-08-27", "VENCIDA_61_90", 61),
            ("2026-07-29", "VENCIDA_61_90", 90),
            ("2026-07-28", "VENCIDA_MAS_90", 91),
        ):
            with self.subTest(due=due_on):
                row = project(ledger(accounts=[
                    account(booked_on="2026-01-01", due_on=due_on)
                ]))["accounts"][0]
                self.assertEqual(band, row["aging_band"])
                self.assertEqual(days, row["overdue_days"])

    def test_aislamiento_por_empresa(self) -> None:
        tenant_b, entity_b = "fixture:tenant/tenant-b", "fixture:legal-entity/entity-b"
        for bad in (
            ledger(tenant_ref=tenant_b), ledger(legal_entity_ref=entity_b),
            ledger(entries=[entry(tenant_ref=tenant_b)]),
            ledger(entries=[entry(legal_entity_ref=entity_b)]),
            ledger(accounts=[account(tenant_ref=tenant_b)]),
            ledger(accounts=[account(legal_entity_ref=entity_b)]),
        ):
            with self.subTest(bad=bad):
                output = project(bad)
                self.assert_safe(output, 0, 0)
                self.assertNotIn("tenant-b", json.dumps(output))
                self.assertNotIn("entity-b", json.dumps(output))
        self.assert_safe(project(tenant=tenant_b), 0, 0)
        self.assert_safe(project(entity=entity_b), 0, 0)
        second = ledger(tenant_ref=tenant_b, legal_entity_ref=entity_b)
        for table in ("entries", "accounts"):
            for row in second[table]:
                row["tenant_ref"] = tenant_b
                row["legal_entity_ref"] = entity_b
        self.assertEqual(project(), project(second, tenant_b, entity_b))

    def test_rechaza_datos_reales(self) -> None:
        invalid = (
            ledger(email="someone@example.com"), ledger(cedula="1234567890"),
            ledger(telefono="3101112233"), ledger(fixture_ref="company.com"),
            ledger(fixture_ref="fixture:finance-ledger/1234567890"),
            ledger(tenant_ref="company@example.com"),
            ledger(tenant_ref="fixture:tenant/1234567890"),
            ledger(legal_entity_ref="fixture:legal-entity/1234567890"),
            ledger(entries=[entry(entry_ref="fixture:finance-entry/1234567890")]),
            ledger(accounts=[account(account_ref="fixture:finance-account/1234567890")]),
            ledger(entries=[entry(email="someone@example.com")]),
            ledger(entries=[entry(document_number="1234567890")]),
            ledger(entries=[entry(amount_decimal=100)]),
            ledger(entries=[entry(amount_decimal=1.2)]),
            ledger(entries=[entry(amount_decimal="1.234")]),
            ledger(entries=[entry(amount_decimal="1e3")]),
            ledger(entries=[entry(amount_decimal="-2.00")]),
            ledger(entries=[entry(amount_decimal="01.00")]),
            ledger(entries=[entry(amount_decimal="9999999999999.00")]),
            ledger(entries=[entry(booked_on="2026-02-30")]),
            ledger(entries=[entry(booked_on="2027-01-01")]),
            ledger(entries=[entry(state="APROBADO")]),
            ledger(entries=[entry(kind="TRANSFERENCIA")]),
            ledger(accounts=[account(email="someone@example.com")]),
            ledger(accounts=[account(amount_decimal="NaN")]),
            ledger(accounts=[account(due_on="2026-02-30")]),
            ledger(accounts=[account(due_on="2026-07-31", booked_on="2026-08-01")]),
            ledger(accounts=[account(state="PAGADA")]),
            ledger(accounts=[account(direction="TRANSFERIR")]),
            ledger(entries={"one": entry()}), ledger(accounts={"one": account()}),
            ledger(as_of="2026-02-30"),
        )
        for bad in invalid:
            with self.subTest(bad=bad):
                output = project(bad)
                self.assert_safe(output, 0, 0)
                self.assertNotIn("someone", json.dumps(output))
                self.assertNotIn("1234567890", json.dumps(output))
        source = CLASS.read_text(encoding="utf-8").lower()
        for unsafe in ("curl_", "file_get_contents(", "mysqli", "pdo(", "httpclient",
                       "shell_exec(", "file_put_contents(", "floatval("):
            self.assertNotIn(unsafe, source)

    def test_sin_cierre_automatico(self) -> None:
        output = project()
        self.assert_safe(output, 1, 1)
        self.assertEqual("REQUIERE_REVISION", output["entries"][0]["state"])
        self.assertEqual("REQUIERE_REVISION", output["accounts"][0]["state"])
        self.assertTrue(output["accounts"][0]["attention_candidate"])
        for unsafe in (
            ledger(entries=[entry(), entry()]),
            ledger(accounts=[account(), account()]),
            ledger(entries=[entry(kind="APROBADO")]),
            ledger(accounts=[account(state="CERRADA")]),
            ledger(entries=[entry()] * 101),
            ledger(accounts=[account()] * 101),
        ):
            with self.subTest(unsafe=unsafe):
                self.assert_safe(project(unsafe), 0, 0)
        self.assert_safe(project(ledger(entries=[], accounts=[])), 0, 0)


if __name__ == "__main__":
    unittest.main()
