"""Contrato offline de la matriz aprobada por OWNER #1868/A; sin navegador ni red."""
from __future__ import annotations

from pathlib import Path
import unittest

DOC = Path(__file__).resolve().parents[1] / "docs/salida-rendimiento-a11y.md"
START = "<!-- QA1499-APPROVED-MATRIX-START -->"
END = "<!-- QA1499-APPROVED-MATRIX-END -->"
EXPECTED = {
    "/": ("Inicio público", "PARCIAL"),
    "/producto": ("Producto público", "PARCIAL"),
    "/admin/login": ("Acceso administrativo", "PARCIAL"),
    "/admin": ("Panel tenant sintético", "PENDIENTE"),
    "/adminpl0n3r": ("Panel plataforma sintético", "PENDIENTE"),
}


def matrix_section(text: str) -> str:
    if text.count(START) != 1 or text.count(END) != 1:
        raise AssertionError("Matriz aprobada ausente o duplicada.")
    return text.split(START, 1)[1].split(END, 1)[0]


def assert_matrix(text: str) -> int:
    lines = [s.strip() for s in matrix_section(text).splitlines() if s.strip().startswith("|")]
    if len(lines) != 7:
        raise AssertionError("Matriz distinta a cinco rutas.")
    table = [[s.strip() for s in line.strip("|").split("|")] for line in lines]
    if table[0] != ["Flujo", "Ruta", "Chrome estable", "Playwright WebKit", "Estado de evidencia"]:
        raise AssertionError("Columnas de matriz alteradas.")
    if table[1] != ["---"] * 5:
        raise AssertionError("Separador inválido.")
    seen = set()
    scenarios = 0
    for columns in table[2:]:
        if len(columns) != 5:
            raise AssertionError("Fila incompleta.")
        flow, literal, chrome, webkit, state = columns
        if not literal.startswith(chr(96)) or not literal.endswith(chr(96)):
            raise AssertionError("Ruta sin delimitadores literales.")
        route = literal.strip(chr(96))
        if route not in EXPECTED or route in seen:
            raise AssertionError("Ruta inesperada o duplicada.")
        expected_flow, expected_state = EXPECTED[route]
        if flow != expected_flow or not state.startswith(expected_state):
            raise AssertionError("Flujo o clasificación de evidencia falsa.")
        if (chrome, webkit) != ("2", "2"):
            raise AssertionError("Falta un perfil de navegador.")
        scenarios += int(chrome) + int(webkit)
        seen.add(route)
    if seen != set(EXPECTED) or scenarios != 20:
        raise AssertionError("Matriz incompleta.")
    return scenarios


class ApprovedMatrixContractTests(unittest.TestCase):
    def test_matrix_20_cases(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        self.assertEqual(assert_matrix(text), 20)
        for token in ("1440×900", "390×844", "**12**", "**8**"):
            with self.subTest(token=token):
                self.assertIn(token, text)

    def test_thresholds_and_authority(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        section = text.split("## Matriz técnica pre-live aprobada — OWNER #1868/A", 1)[1]
        for token in (
            "/decidir A", "6713272297", "6713278956",
            "LCP p75 ≤ 2500 ms", "INP p75 ≤ 200 ms", "CLS p75 ≤ 0,10",
            "axe serios/críticos: 0", "teclado 100%",
            "NO VERIFICADO", "Safari nativo", "datos reales",
            "go-live", "localhost", "SHA exacto",
        ):
            with self.subTest(token=token):
                self.assertIn(token, section)
        self.assertRegex(section, r"main@[0-9a-f]{40}")
        self.assertIn("SIN DECIDIR", text)
        self.assertIn("NO AUTORIZADAS", text)

    def test_mutation_guard(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        self.assertEqual(assert_matrix(text), 20)
        with self.assertRaises(AssertionError):
            assert_matrix(text.replace("| 2 | 2 |", "| 2 | 0 |", 1))
        with self.assertRaises(AssertionError):
            assert_matrix(text.replace("| " + chr(96) + "/admin" + chr(96) + " |",
                                       "| " + chr(96) + "/adminx" + chr(96) + " |", 1))


if __name__ == "__main__":
    unittest.main()
