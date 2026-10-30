import unittest
from pathlib import Path

CSS = Path(__file__).resolve().parents[1] / "frontend" / "admin" / "admin.css"


class AdminCssPolishTests(unittest.TestCase):
    def test_focus_and_hit_area(self):
        css = CSS.read_text(encoding="utf-8")
        self.assertIn("select:focus-visible", css)
        self.assertIn("button:focus-visible", css)
        self.assertNotIn("min-height: 42px", css)


if __name__ == "__main__":
    unittest.main()
