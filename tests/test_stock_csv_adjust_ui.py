#!/usr/bin/env python3
"""Contract acceptance for #929: preview is read-only and commit requires confirmation."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/StockCsvAdjust.tsx"
INVENTORY = ROOT / "frontend/admin/InventoryManagement.tsx"
CONTROLLER = ROOT / "src/Http/Controller/InventoryCsvAdjustController.php"


class UiAjusteCsvInventarioTests(unittest.TestCase):
    def test_acceptance(self):
        panel = PANEL.read_text(encoding="utf-8")
        inventory = INVENTORY.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        self.assertIn("import { StockCsvAdjust } from './StockCsvAdjust';", inventory)
        self.assertIn("<StockCsvAdjust", inventory)
        self.assertIn("canUpdate={canUpdate}", inventory)
        self.assertIn("onCommitted={() => { void loadInventory(); }}", inventory)
        self.assertIn("onSubmit={(event) => void previewCsv(event)}", panel)
        self.assertIn("onClick={() => void commitCsv()}", panel)
        self.assertIn("type=\"button\"", panel)
        self.assertIn("if (!canUpdate || !confirmed || !preview", panel)
        self.assertIn("preview.mode !== 'preview'", panel)
        self.assertIn("status !== 'ready'", panel)
        self.assertIn("hasErrors || !csv", panel)
        self.assertIn("const hasErrors = preview?.rows.some((row) => Boolean(row.error))", panel)
        self.assertIn("Corrige el archivo y vuelve a generar la vista previa", panel)
        self.assertIn("report.rows.map((row)", panel)
        self.assertIn("rowResult(row)", panel)
        self.assertIn("type=\"file\"", panel)
        self.assertIn("type=\"checkbox\"", panel)
        self.assertIn("aria-live=\"polite\"", panel)
        self.assertIn("role=\"alert\"", panel)
        self.assertIn("credentials: 'same-origin'", panel)
        self.assertIn("'X-CSRF-Token': csrfToken", panel)
        self.assertIn("body: JSON.stringify({ csv: content })", panel)
        self.assertIn("'/api/inventory/adjust-csv/' + mode", panel)
        self.assertIn("data.file_sha256 !== expectedHash", panel)
        self.assertIn("if (attempt !== sequence.current)", panel)
        self.assertNotIn("style={{", panel)
        self.assertNotIn("tenant_id:", panel)
        self.assertIn("'/api/inventory/adjust-csv/preview'", controller)
        self.assertIn("'/api/inventory/adjust-csv/commit'", controller)
        self.assertIn("'inventory.update'", controller)

    def test_rejected_rows_fail_closed_without_commit(self):
        panel = PANEL.read_text(encoding="utf-8")
        block = panel.split("async function commitCsv()", 1)[1].split(
            "const attempt = ++sequence.current;", 1
        )[0]
        self.assertIn("!confirmed", block)
        self.assertIn("hasErrors", block)
        self.assertIn("!hasValidRows", block)
        self.assertIn("status !== 'ready'", block)
        self.assertIn("!canUpdate", block)

    def test_new_file_invalidates_stale_preview(self):
        panel = PANEL.read_text(encoding="utf-8")
        select = panel.split("async function selectFile(", 1)[1].split(
            "async function sendCsv(", 1
        )[0]
        for marker in ("++sequence.current", "setPreview(null)",
                       "setConfirmed(false)", "setResult(null)",
                       "MAX_CSV_BYTES", "file.text()"):
            self.assertIn(marker, select)


if __name__ == "__main__":
    unittest.main()
