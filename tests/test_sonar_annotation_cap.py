"""Regression contract for Sonar's truncated GitHub check annotations.

The Sonar API must still return all issues before HIGH/CRITICAL classification.
No network access or SonarCloud credentials are used in these tests.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "authenticated-production-smoke.yml"


def is_incomplete(findings, total, expected):
    """Mirror the exact source predicate, with the GitHub check 50-annotation cap."""
    return (
        not isinstance(findings, list)
        or type(total) is not int
        or total != len(findings)
        or expected != min(total, 50)
        or total > 100
    )


class SonarAnnotationCapTests(unittest.TestCase):
    def test_annotations_cap_does_not_hide_severe_findings(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        for required in (
            "or total != len(findings)",
            "or (total <= 50 and total != expected)",
            "or expected != min(total, 50) or total > 100",
        ):
            self.assertIn(required, workflow)
        self.assertIn("matching[0].get(\"output\", {}).get(\"annotations_count\")", workflow)
        self.assertIn("and item.get(\"head_sha\") == sha", workflow)
        self.assertIn("and item.get(\"conclusion\") == \"success\"", workflow)
        self.assertIn("blocked.append(", workflow)
        self.assertIn('quality in {"SECURITY", "RELIABILITY"} and rank in severe', workflow)
        self.assertIn('sys.exit(0 if state == "NO_HIGH_FINDINGS" else 1)', workflow)

        for count in (0, 1, 49, 50, 55, 100):
            with self.subTest(size=count):
                complete = [{} for _ in range(count)]
                self.assertFalse(is_incomplete(complete, count, min(count, 50)))
                if count:
                    self.assertTrue(is_incomplete(complete[:-1], count, min(count, 50)))
                self.assertTrue(is_incomplete(complete, count, min(count, 50) + 1))
                if count:
                    self.assertTrue(is_incomplete(complete, count, min(count, 50) - 1))

        self.assertTrue(is_incomplete([{}] * 101, 101, 50))
        self.assertTrue(is_incomplete([], True, 0))
        self.assertTrue(is_incomplete([], "0", 0))
        self.assertTrue(is_incomplete(None, 0, 0))
        self.assertTrue(is_incomplete([], 0, None))
        self.assertTrue(is_incomplete([], 0, False))


if __name__ == "__main__":
    unittest.main()
