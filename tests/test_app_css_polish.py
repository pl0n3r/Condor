import re
import unittest
from pathlib import Path

CSS = Path(__file__).resolve().parents[1] / "public" / "app.css"


class AppCssPolishTests(unittest.TestCase):
    def test_polish_block_present(self):
        css = CSS.read_text(encoding="utf-8")
        self.assertIn("prefers-reduced-motion: reduce", css)
        self.assertIn("text-wrap: balance", css)
        self.assertIn("tabular-nums", css)
        self.assertIn(".button:active { scale: 0.96; }", css)
        self.assertIsNone(re.search(r"transition(-property)?\s*:\s*all\b", css))


if __name__ == "__main__":
    unittest.main()
