#!/usr/bin/env python3
"""Acceptance for #724: keyboard-driven inventory scan UI (pre-live)."""

from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PANEL = (ROOT / "frontend/admin/InventoryManagement.tsx").read_text(encoding="utf-8")
API = (ROOT / "src/Http/Controller/ScanController.php").read_text(encoding="utf-8")


class EscaneoUiInventarioTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """Submitting a code selects its tenant-scoped variant and movements."""
        self.assertIn('async function scanVariant(event: FormEvent<HTMLFormElement>)', PANEL)
        self.assertIn("inventoryPath(branchId) + '/scan/' + encodeURIComponent(code)", PANEL)
        self.assertIn("credentials: 'same-origin'", PANEL)
        self.assertIn("onSubmit={(event) => void scanVariant(event)}", PANEL)
        self.assertIn("type=\"text\"", PANEL)
        self.assertIn("value={scanCode}", PANEL)
        self.assertIn("setScannedVariantId(variant.id)", PANEL)
        self.assertIn("setAdjustVariant(variant.id)", PANEL)
        self.assertIn("setTransferVariant(variant.id)", PANEL)
        self.assertIn("movement.variant_id === scannedVariantId", PANEL)
        self.assertIn("visibleMovements.map((movement)", PANEL)
        self.assertIn("movementHeadingRef.current?.focus()", PANEL)
        self.assertIn("Código no encontrado en esta sede.", PANEL)
        self.assertIn("Esta variante no tiene movimientos recientes.", PANEL)
        self.assertIn("value={scanCode}", PANEL)
        self.assertNotIn("tenant_id:", PANEL)

    def test_unknown_codes_and_errors_do_not_leave_a_stale_selection(self) -> None:
        self.assertIn("if (response.status === 404)", PANEL)
        self.assertIn("setScanStatus('not-found')", PANEL)
        self.assertIn("setScannedVariantId(null)", PANEL)
        self.assertIn("setScanStatus('error')", PANEL)
        self.assertIn("role=\"alert\"", PANEL)
        self.assertIn("setScanMessage('Código no disponible en el inventario de esta sede.')", PANEL)
        self.assertIn("state.data.variants.find(", PANEL)
        self.assertIn("item.id === result.variant?.id", PANEL)

    def test_branch_switch_and_outdated_responses_are_discarded(self) -> None:
        self.assertIn("scanRequestId.current += 1", PANEL)
        self.assertIn("if (attempt !== scanRequestId.current)", PANEL)
        self.assertIn("if (scanRequestId.current === attempt)", PANEL)
        self.assertIn("setScanCode('')", PANEL)
        self.assertIn("}, [branchId, canView]);", PANEL)
        self.assertIn("function clearScanSelection()", PANEL)

    def test_authoritative_api_is_branch_and_tenant_scoped(self) -> None:
        self.assertIn(
            "$this->authorizedBranchScope($branchId, 'inventory.view')",
            API,
        )
        self.assertIn("'tenant' => $tenant", API)
        self.assertIn("new NotFoundHttpException('Código no encontrado.')", API)
        self.assertIn("Cache-Control', 'private, no-store'", API)


if __name__ == "__main__":
    unittest.main()
