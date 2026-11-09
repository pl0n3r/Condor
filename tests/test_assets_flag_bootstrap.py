"""Criterios #1671: el SSR y la UI de Activos no amplían autoridad sin flag."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AssetsFlagBootstrapTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "src/Http/Controller/AdminController.php").read_text()
        cls.twig = (ROOT / "templates/admin/index.html.twig").read_text()
        cls.app = (ROOT / "frontend/admin/AdminApp.tsx").read_text()
        cls.view = (ROOT / "frontend/admin/AssetsManagement.tsx").read_text()
        cls.api = (ROOT / "src/Http/Controller/AssetsController.php").read_text()

    def test_criterio_principal(self):
        self.assertIn(
            "'assets_real_data_enabled' => getenv('ASSETS_REAL_DATA_ENABLED') === 'true'",
            self.controller,
        )
        self.assertIn(
            'data-assets-real-data-enabled="{{ assets_real_data_enabled ?',
            self.twig,
        )
        self.assertIn("dataset.assetsRealDataEnabled === 'true'", self.app)
        self.assertIn("assetsRealDataEnabled ? [{ href: '/admin#assets-management'", self.app)
        self.assertIn("(assetsRealDataEnabled || import.meta.env.DEV)", self.app)
        self.assertIn("enabled={assetsRealDataEnabled}", self.app)
        self.assertIn("import('./AssetsManagement')", self.app)
        self.assertIn("<AsyncAssetsManagement", self.app)
        self.assertIn("companyId={context.data.active_legal_entity?.id !== undefined", self.app)
        self.assertIn(
            "context.data.active_branch.legal_entity?.id === context.data.active_legal_entity.id",
            self.app,
        )
        for token in ("assets-real/records", "record_ref", "category", "expected_version",
                      "next_cursor", "PageHeader", "MetricStrip", "Toolbar",
                      "DataTable", "SidePanel", "X-CSRF-Token", "credentials: 'same-origin'"):
            with self.subTest(token=token):
                self.assertIn(token, self.view)

    def test_criterio_seguridad_y_bordes(self):
        self.assertIn("if (!enabled) return <section", self.view)
        self.assertIn("if (!opt.enabled) return { status: 'disabled' };", self.view)
        self.assertIn("if (!opt.allowed", self.view)
        self.assertIn("!allowed) return;", self.view)
        self.assertIn("'feature_unavailable'", self.api)
        self.assertIn("getenv('ASSETS_REAL_DATA_ENABLED') === 'true'", self.api)
        for token in ("requireCsrf(request: $request)", "Membership::ROLE_OWNER",
                      "inventory.view", "inventory.create", "inventory.update",
                      "expected_version", "$legalEntity?->tenant()->id() !== $tenant->id()"):
            with self.subTest(token=token):
                self.assertIn(token, self.api)
        self.assertNotIn("fixture:", self.view)
        for secret in ("record_ref", "actor_id", "company_id", "csrf_token"):
            self.assertNotIn(secret, self.twig)
        self.assertIn("role=\"alert\"", self.view)
        self.assertIn("role=\"status\"", self.view)
        self.assertIn("No se pudo consultar", self.view)
        self.assertIn("No hay activos", self.view)


if __name__ == "__main__":
    unittest.main()
