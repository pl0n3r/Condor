#!/usr/bin/env python3
"""#1616: ejecutar el reconocimiento de testigos PHPUnit con y sin ANSI."""
from __future__ import annotations

import runpy
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parse = runpy.run_path(str(ROOT / "tests/test_cobertura_condor_11.py"))[
    "phpunit_summary_has_real_tests"
]


class PhpunitAnsiOutputTests(unittest.TestCase):
    def test_ansi_y_sin_color_con_pruebas_reales(self):
        for output in (
            "\x1b[30;42mOK (1 test, 8 assertions)\x1b[0m",
            "\x1b[30;42mOK (4 tests, 26 assertions)\x1b[0m",
            "OK (4 tests, 26 assertions)",
            "Tests: 3, Assertions: 8",
            "\x1b[0mTests: 4, Assertions: 26",
        ):
            with self.subTest(output=repr(output)):
                self.assertTrue(parse(output))

    def test_no_infiere_cobertura_de_cero_o_mensajes_arbitrarios(self):
        for output in (
            "",
            "No tests executed!",
            "OK (0 tests, 0 assertions)",
            "Tests: 0, Assertions: 0",
            "FATAL: PHPUnit falló",
            "Fake result: OK (1 test)",
            "\x1b[31mTests: 0, Assertions: 0\x1b[0m",
        ):
            with self.subTest(output=repr(output)):
                self.assertFalse(parse(output))


if __name__ == "__main__":
    unittest.main()
