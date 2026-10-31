"""Regresión estática de estilos y artefacto de admin (#1430)."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "frontend" / "admin" / "admin.css"
BUILD = ROOT / "public" / "build" / "admin.css"


class AdminCssPolishTests(unittest.TestCase):
    def test_focus_and_hit_area(self):
        source = SOURCE.read_text(encoding="utf-8")
        built = BUILD.read_text(encoding="utf-8")
        for tag in ("select", "button", "textarea", "summary", "input"):
            with self.subTest(control=tag):
                self.assertIn(f"{tag}:focus-visible", source)
                self.assertIn(f"{tag}:focus-visible", built)
        self.assertRegex(source, r"outline:\s*3px\s+solid\s+var\(")
        self.assertRegex(source, r"outline-offset:\s*2px")
        self.assertRegex(built, r"outline:3px solid var\(")
        self.assertIn("outline-offset:2px", built)
        self.assertIn("min-height: 44px", source)
        self.assertIn("min-height:44px", built)
        self.assertNotRegex(source, r"min-height:\s*42px")
        self.assertNotRegex(built, r"min-height:\s*42px")
        self.assertRegex(source, r":where\(input,\s*select,\s*button,\s*textarea,\s*summary\)")
        self.assertRegex(built, r":where\(input,select,button,textarea,summary\)")
        self.assertIn("font-variant-numeric: tabular-nums", source)
        self.assertIn("font-variant-numeric:tabular-nums", built)

    def test_contract_is_not_vacuous(self):
        source = SOURCE.read_text(encoding="utf-8")
        self.assertIn(".branch-picker select", source)
        self.assertIn(".field input", source)
        self.assertIn(".field select", source)
        self.assertRegex(source, r"min-height:\s*44px")
        # Reversion of keyboard focus rules must fail the main test.
        self.assertEqual(len(re.findall(r"\b(?:select|button|textarea|summary|input):focus-visible", source)), 5)


if __name__ == "__main__":
    unittest.main()
