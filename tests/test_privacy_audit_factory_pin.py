#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/auditoria-privacidad.yml"
DATOS = ROOT / "datos.yml"

OLD_SHA = "68eef82e3b21939143a4cbea23b7df2615534e77"
EXPECTED_USES = "uses: pl0n3r/factory/.github/workflows/auditoria-privacidad.yml@v1"


class PrivacyAuditFactoryPinTests(unittest.TestCase):
    def workflow(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def test_ac01_weekly_audit_uses_factory_v1(self) -> None:
        text = self.workflow()
        self.assertIn(EXPECTED_USES, text)
        self.assertIn("kit_ref: v1", text)
        self.assertNotIn("@main", text)
        self.assertNotIn(OLD_SHA, text)

    def test_ac02_datos_yml_does_not_silence_health(self) -> None:
        data = json.loads(DATOS.read_text(encoding="utf-8"))
        fields = {
            field
            for treatment in data.get("treatments", [])
            for field in treatment.get("fields", [])
        }
        self.assertNotIn("health", fields)

    def test_ac03_rejects_historical_sha_main_or_wrong_kit_ref(self) -> None:
        text = self.workflow()
        self.assertEqual(text.count(EXPECTED_USES), 1)
        self.assertEqual(text.count("kit_ref: v1"), 1)
        self.assertNotIn(OLD_SHA, text)
        self.assertNotIn(
            "uses: pl0n3r/factory/.github/workflows/auditoria-privacidad.yml@main",
            text,
        )

    def test_ac04_keeps_minimum_triggers_permissions_and_no_secrets(self) -> None:
        text = self.workflow()
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("schedule:", text)
        self.assertIn("cron: '17 8 * * 1'", text)
        self.assertIn("contents: read", text)
        self.assertIn("issues: write", text)
        self.assertIn("label_language: es", text)
        self.assertNotIn("secrets:", text)
        self.assertNotIn("permissions: write-all", text)
        self.assertNotIn("contents: write", text)


if __name__ == "__main__":
    unittest.main()
