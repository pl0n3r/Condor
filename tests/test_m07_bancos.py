#!/usr/bin/env python3
"""M07 S03-S06: real-PHP synthetic finance acceptance, without mocks."""
from __future__ import annotations

import copy
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS = ROOT / "src/Application/Finance/FinanceReconciliationBudget.php"
TENANT = "fixture:tenant/tenant-a"
ENTITY = "fixture:legal-entity/entity-a"


def period(**kwargs: object) -> dict:
    base = dict(period_ref="fixture:finance-period/p-1", tenant_ref=TENANT,
                legal_entity_ref=ENTITY, starts_on="2026-10-01", ends_on="2026-10-27",
                state="BORRADOR")
    return {**base, **kwargs}


def budget(**kwargs: object) -> dict:
    base = dict(budget_ref="fixture:finance-budget/b-1", tenant_ref=TENANT,
                legal_entity_ref=ENTITY, period_ref="fixture:finance-period/p-1",
                kind="INGRESO", planned_decimal="20.00", state="BORRADOR")
    return {**base, **kwargs}


def entry(**kwargs: object) -> dict:
    base = dict(entry_ref="fixture:finance-entry/e-1", tenant_ref=TENANT,
                legal_entity_ref=ENTITY, period_ref="fixture:finance-period/p-1",
                budget_ref="fixture:finance-budget/b-1", amount_decimal="12.30",
                kind="INGRESO", booked_on="2026-10-20", state="BORRADOR")
    return {**base, **kwargs}


def movement(**kwargs: object) -> dict:
    base = dict(movement_ref="fixture:finance-movement/m-1", tenant_ref=TENANT,
                legal_entity_ref=ENTITY, entry_ref="fixture:finance-entry/e-1",
                amount_decimal="12.30", kind="INGRESO", booked_on="2026-10-20",
                state="BORRADOR")
    return {**base, **kwargs}


def fixture(**kwargs: object) -> dict:
    base = dict(fixture_ref="fixture:finance-reconciliation/r-1", tenant_ref=TENANT,
                legal_entity_ref=ENTITY, as_of="2026-10-27",
                ledger_entries=[entry()], bank_movements=[movement()],
                budgets=[budget()], periods=[period()])
    return {**base, **kwargs}


def project(payload: dict | None = None, tenant: str = TENANT, entity: str = ENTITY) -> dict:
    script = r"""
require $argv[1];
use App\Application\Finance\FinanceReconciliationBudget;
$data = json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR);
echo json_encode(FinanceReconciliationBudget::project(
    $data['fixture'], $data['tenant'], $data['entity']
), JSON_THROW_ON_ERROR);
"""
    result = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", script, str(CLASS),
         json.dumps({"fixture": fixture() if payload is None else payload,
                     "tenant": tenant, "entity": entity})],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return json.loads(result.stdout)


class FinanceReconciliationBudgetTests(unittest.TestCase):
    def test_resultado_determinista(self) -> None:
        first = project()
        self.assertEqual(first, project(copy.deepcopy(fixture())))
        self.assertEqual("12.30", first["budgets"][0]["actual_decimal"])
        self.assertEqual("-7.70", first["budgets"][0]["variance_decimal"])
        self.assertEqual(1, first["matched_candidate_count"])
        self.assertTrue(first["bank_movements"][0]["candidate_match"])
        many = fixture(
            ledger_entries=[entry(), entry(entry_ref="fixture:finance-entry/e-2",
                                           amount_decimal="0.10")],
            bank_movements=[movement(), movement(movement_ref="fixture:finance-movement/m-2",
                                                entry_ref="fixture:finance-entry/e-2",
                                                amount_decimal="0.10")])
        reversed_rows = copy.deepcopy(many)
        reversed_rows["ledger_entries"].reverse()
        reversed_rows["bank_movements"].reverse()
        self.assertEqual(project(many), project(reversed_rows))
        self.assertEqual("12.40", project(many)["budgets"][0]["actual_decimal"])

    def test_aislamiento_por_empresa(self) -> None:
        tenant_b, entity_b = "fixture:tenant/tenant-b", "fixture:legal-entity/entity-b"
        for sample in (
            fixture(tenant_ref=tenant_b), fixture(legal_entity_ref=entity_b),
            fixture(ledger_entries=[entry(tenant_ref=tenant_b)]),
            fixture(bank_movements=[movement(legal_entity_ref=entity_b)]),
            fixture(budgets=[budget(tenant_ref=tenant_b)]),
            fixture(periods=[period(legal_entity_ref=entity_b)]),
        ):
            with self.subTest(sample=sample):
                self.assertEqual([], project(sample)["periods"])
                self.assertNotIn("tenant-b", json.dumps(project(sample)))
                self.assertNotIn("entity-b", json.dumps(project(sample)))
        self.assertEqual([], project(tenant=tenant_b)["bank_movements"])
        foreign = copy.deepcopy(fixture())
        foreign["tenant_ref"] = tenant_b
        foreign["legal_entity_ref"] = entity_b
        for kind in ("ledger_entries", "bank_movements", "budgets", "periods"):
            for record in foreign[kind]:
                record["tenant_ref"] = tenant_b
                record["legal_entity_ref"] = entity_b
        self.assertEqual(project(), project(foreign, tenant_b, entity_b))

    def test_rechaza_datos_reales(self) -> None:
        for bad in (
            fixture(email="person@example.com"), fixture(cedula="1012345678"),
            fixture(tenant_ref="foo@example.com"),
            fixture(legal_entity_ref="1234567890"),
            fixture(ledger_entries=[entry(email="person@example.com")]),
            fixture(ledger_entries=[entry(entry_ref="fixture:finance-entry/1234567890")]),
            fixture(bank_movements=[movement(movement_ref="https://bank.example.com")]),
            fixture(budgets=[budget(planned_decimal=123.40)]),
            fixture(budgets=[budget(planned_decimal="1e3")]),
            fixture(periods=[period(starts_on="2026-02-30")]),
            fixture(bank_movements=[movement(movement_ref="fixture:finance-movement/2345678901")]),
            fixture(ledger_entries=[entry(), entry()]),
            fixture(bank_movements=[None, movement()]),
        ):
            with self.subTest(bad=bad):
                result = project(bad)
                self.assertEqual([], result["bank_movements"])
                self.assertEqual([], result["periods"])
                self.assertNotIn("@", json.dumps(result))

    def test_sin_cierre_automatico(self) -> None:
        value = project()
        self.assertEqual("REQUIERE_REVISION", value["state"])
        self.assertFalse(value["can_close"])
        self.assertFalse(value["can_approve"])
        self.assertTrue(value["synthetic_only"])
        self.assertTrue(value["periods"][0]["review_candidate"])
        self.assertFalse(value["periods"][0]["can_close"])
        mismatch = project(fixture(bank_movements=[movement(amount_decimal="12.31")]))
        self.assertFalse(mismatch["periods"][0]["review_candidate"])
        self.assertEqual(1, mismatch["unmatched_movement_count"])
        self.assertFalse(mismatch["can_close"])
        extra = project(fixture(bank_movements=[
            movement(), movement(movement_ref="fixture:finance-movement/m-2")]))
        self.assertEqual(1, extra["matched_candidate_count"])
        self.assertFalse(extra["periods"][0]["review_candidate"])
        bad = project(fixture(periods=[period(state="CERRADO")]))
        self.assertEqual([], bad["periods"])
        source = CLASS.read_text(encoding="utf-8").lower()
        for forbidden in ("curl_", "pdo(", "doctrine", "file_get_contents(",
                          "shell_exec(", "httpclient", "entitymanager"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
