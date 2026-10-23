#!/usr/bin/env python3
"""Contrato offline del buscador global en el encabezado de Condor."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SHELL = (ROOT / "frontend/admin/AdminShell.tsx").read_text(encoding="utf-8")
API = (ROOT / "src/Http/Controller/AdminSearchController.php").read_text(
    encoding="utf-8"
)


class BusquedaGlobalUiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        """La barra consulta desde 2 caracteres y ofrece grupos y teclado."""
        self.assertIn("function AdminGlobalSearch()", SHELL)
        self.assertIn("variant === 'tenant' && <AdminGlobalSearch />", SHELL)
        self.assertIn("event.key !== '/'", SHELL)
        self.assertIn("event.key === 'Escape'", SHELL)
        self.assertIn("event.key === 'ArrowDown'", SHELL)
        self.assertIn("event.key === 'ArrowUp'", SHELL)
        self.assertIn("event.key === 'Enter'", SHELL)
        self.assertIn("term.length < 2", SHELL)
        self.assertIn("encodeURIComponent(term)", SHELL)
        self.assertIn("/api/admin/search?q=", SHELL)
        self.assertIn("Productos", SHELL)
        self.assertIn("Clientes", SHELL)
        self.assertIn("Pedidos", SHELL)
        self.assertIn('role="search"', SHELL)
        self.assertIn('role="region"', SHELL)
        self.assertIn('role="status"', SHELL)
        self.assertIn('role="alert"', SHELL)
        self.assertIn("Sin resultados.", SHELL)

    def test_escape_closes_results_after_refocusing_input(self) -> None:
        """Focus can reopen the dropdown: close it only after the focus transition."""
        escape_handler = re.search(
            r"if \(event\.key === 'Escape'\) \{(?P<body>.*?)\breturn;",
            SHELL,
            re.DOTALL,
        )
        self.assertIsNotNone(escape_handler)
        body = escape_handler.group("body")
        self.assertLess(
            body.index("inputRef.current?.focus();"),
            body.index("setVisible(false);"),
        )

    def test_safe_requests_and_stale_response_protection(self) -> None:
        self.assertIn("credentials: 'same-origin'", SHELL)
        self.assertIn("new AbortController()", SHELL)
        self.assertIn("controller.abort()", SHELL)
        self.assertIn("window.clearTimeout(timer)", SHELL)
        self.assertIn("controller.signal.aborted", SHELL)
        self.assertRegex(SHELL, r"if \(!response\.ok\)")
        self.assertIn("maxLength={64}", SHELL)
        self.assertIn("term.length > 64", SHELL)
        self.assertIn("target.isContentEditable", SHELL)
        self.assertIn("entry.href.startsWith('//')", SHELL)
        self.assertNotIn("localStorage", SHELL)
        self.assertNotIn("console.log", SHELL)

    def test_navigation_respects_server_permissions(self) -> None:
        self.assertIn("href={item.href}", SHELL)
        self.assertRegex(SHELL, r"results\[key\]\.map\(\(item\)")
        self.assertIn("this->authorization->hasAnyPermission(", API)
        self.assertIn("'catalog.view'", API)
        self.assertIn("'customers.view'", API)
        self.assertIn("'orders.view'", API)
        self.assertIn("'Cache-Control'", API)
        self.assertIn("limitSession($request)", API)


if __name__ == "__main__":
    unittest.main()
