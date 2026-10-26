#!/usr/bin/env python3
"""Condor #814: regresión de vista previa y confirmación explícita CSV."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "frontend/admin/CatalogManagement.tsx"
COMMERCE = ROOT / "frontend/admin/CommerceManagement.tsx"

class ImportacionAdminUiTests(unittest.TestCase):
    def test_acceptance(self):
        cat = CATALOG.read_text(encoding="utf-8")
        com = COMMERCE.read_text(encoding="utf-8")
        self.assertIn('kind="catalog"', cat)
        self.assertIn('kind="customers"', com)
        self.assertIn('canCreate && (', cat)
        self.assertIn('canCreateCustomers && (', com)
        self.assertIn('onCompleted={() => void loadCatalog()}', cat)
        self.assertIn('onCompleted={() => void loadCommerce()}', com)
        self.assertIn("'/api/catalog/import'", cat)
        self.assertIn("'/api/customers/import'", cat)
        self.assertIn("endpoint + '/preview'", cat)
        self.assertIn("endpoint + '/commit'", cat)
        self.assertIn('branch_id: branchId, csv: text', cat)
        self.assertIn('branch_id: branchId, csv })', cat)
        self.assertIn('headers: mutationHeaders(csrfToken)', cat)
        self.assertIn("'X-CSRF-Token': csrfToken", cat)
        self.assertIn("credentials: 'same-origin'", cat)
        self.assertIn('type="file"', cat)
        self.assertIn('accept=".csv,text/csv"', cat)
        self.assertIn("file.size > 262144", cat)
        self.assertIn("file.text()", cat)
        self.assertIn("Generar vista previa", cat)
        self.assertIn("<caption>Validación y errores por fila del CSV</caption>", cat)
        self.assertIn("preview.rows.map", cat)
        self.assertIn("row.errors?.join('; ')", cat)
        self.assertIn("preview.errors > 0", cat)
        self.assertIn("preview.valid === 0", cat)
        self.assertIn("Confirmar importación", cat)
        self.assertIn("role={notice.kind === 'error' ? 'alert' : 'status'}", cat)

    def test_commit_requires_real_preview_and_user_click(self):
        cat = CATALOG.read_text(encoding="utf-8")
        preview = cat.split("async function requestPreview(", 1)[1].split(
            "async function confirmImport()", 1
        )[0]
        commit = cat.split("async function confirmImport()", 1)[1].split(
            "\n  return (", 1
        )[0]
        self.assertNotIn("endpoint + '/commit'", preview)
        self.assertIn("!preview || csv === null || preview.valid === 0", commit)
        self.assertIn("fetch(endpoint + '/commit'", commit)
        self.assertIn("setPreview(null)", commit)
        self.assertIn("setCsv(null)", commit)
        self.assertIn("onClick={() => void confirmImport()}", cat)
        self.assertRegex(cat, r'onClick=\{\(\) => void confirmImport\(\)\}')
        self.assertIn('type="button"', cat)

    def test_switching_file_or_branch_revokes_preview(self):
        cat = CATALOG.read_text(encoding="utf-8")
        select = cat.split("function selectCsv(", 1)[1].split(
            "async function requestPreview(", 1
        )[0]
        self.assertIn("setCsv(null)", select)
        self.assertIn("setPreview(null)", select)
        self.assertIn("key={branchId}", cat)
        self.assertIn("key={branchId}", COMMERCE.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
