"""AC-01 for the read-only inventory restock suggestions panel (#930)."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PANEL = ROOT / "frontend/admin/RestockSuggestions.tsx"
INVENTORY = ROOT / "frontend/admin/InventoryManagement.tsx"
API = ROOT / "src/Http/Controller/RestockSuggestionsController.php"


class UiResurtidoTests(unittest.TestCase):
    def setUp(self):
        self.panel = PANEL.read_text(encoding="utf-8")
        self.inventory = INVENTORY.read_text(encoding="utf-8")
        self.controller = API.read_text(encoding="utf-8")

    def test_acceptance(self):
        """Urgency order, empty/error/loading states and permissions."""
        self.assertIn("export function RestockSuggestions(", self.panel)
        self.assertIn("a.coverage_days - b.coverage_days", self.panel)
        self.assertIn("b.suggested_quantity - a.suggested_quantity", self.panel)
        self.assertIn("Prioridad por menor cobertura disponible", self.panel)
        self.assertIn("No hay sugerencias de resurtido", self.panel)
        self.assertIn("No fue posible consultar el resurtido", self.panel)
        self.assertIn("Cargando sugerencias de resurtido", self.panel)
        self.assertIn("permissions.includes('inventory.view')", self.panel)
        self.assertIn("if (!canView)", self.panel)
        self.assertIn("role=\"alert\"", self.panel)
        self.assertIn('aria-live="polite"', self.panel)
        self.assertIn('scope="col"', self.panel)
        self.assertIn('scope="row"', self.panel)
        self.assertIn("import { RestockSuggestions } from './RestockSuggestions';", self.inventory)
        self.assertIn("<RestockSuggestions", self.inventory)

    def test_existing_server_read_only_contract_and_scope(self):
        self.assertIn("'/api/inventory/restock-suggestions?branch_id='", self.panel)
        self.assertIn("encodeURIComponent(branchId)", self.panel)
        self.assertIn("credentials: 'same-origin'", self.panel)
        self.assertIn("cache: 'no-store'", self.panel)
        self.assertIn("value.branch_id !== branchId", self.panel)
        self.assertIn("item.source_id === value.source_id", self.panel)
        self.assertIn("value.window_days !== 30", self.panel)
        self.assertIn("!value.suggestions.every(suggestion)", self.panel)
        self.assertIn("controller.abort()", self.panel)
        self.assertIn("if (current)", self.panel)
        self.assertIn("methods: ['GET']", self.controller)
        self.assertIn("'inventory.view'", self.controller)
        self.assertIn("'branch_id' => $branch->id()", self.controller)
        self.assertNotIn("localStorage", self.panel)
        self.assertNotIn("dangerouslySetInnerHTML", self.panel)
        self.assertNotIn("style={{", self.panel)

    def test_no_mutating_actions_or_invented_demand(self):
        for forbidden in ("method: 'POST'", "method: 'PUT'",
                          "method: 'DELETE'", "inventory.update",
                          "Math.random()", "window.localStorage"):
            self.assertNotIn(forbidden, self.panel)
        self.assertIn("value.consumed_30d > 0", self.panel)
        self.assertIn("value.suggested_quantity > 0", self.panel)
        self.assertIn("Number.isSafeInteger(value)", self.panel)
        self.assertIn("Number.isFinite(value)", self.panel)


if __name__ == "__main__":
    unittest.main()
