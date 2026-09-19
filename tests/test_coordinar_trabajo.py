#!/usr/bin/env python3

from __future__ import annotations

import unittest

from scripts.coordinar_trabajo import (
    authorized,
    closing_issues,
    file_overlaps,
    issue_from_branch,
    latest_reservation,
    reservation_marker,
)


class CoordinacionTests(unittest.TestCase):
    def test_issue_from_branch(self) -> None:
        self.assertEqual(issue_from_branch("trabajo/issue-12"), 12)
        self.assertEqual(issue_from_branch("trabajo/issue-999"), 999)
        self.assertIsNone(issue_from_branch("feature/algo"))
        self.assertIsNone(issue_from_branch("trabajo/issue-x"))

    def test_closing_issues(self) -> None:
        body = "Closes #12\nFixes #18\nresolves #21"
        self.assertEqual(closing_issues(body), {12, 18, 21})

    def test_authorized_associations(self) -> None:
        self.assertTrue(authorized("OWNER"))
        self.assertTrue(authorized("MEMBER"))
        self.assertTrue(authorized("COLLABORATOR"))
        self.assertFalse(authorized("NONE"))
        self.assertFalse(authorized("CONTRIBUTOR"))

    def test_latest_reservation_prefers_last_marker(self) -> None:
        first = reservation_marker("agente-a", "trabajo/issue-12", True, "tomar")
        second = reservation_marker("agente-a", "trabajo/issue-12", False, "liberar")
        comments = [{"body": first}, {"body": "texto"}, {"body": second}]
        latest = latest_reservation(comments)
        self.assertIsNotNone(latest)
        assert latest is not None
        self.assertEqual(latest["owner"], "agente-a")
        self.assertFalse(latest["active"])

    def test_file_overlap_detects_collisions(self) -> None:
        current = {"src/a.php", "README.md", "src/b.php"}
        others = {
            10: {"src/a.php", "src/x.php"},
            11: {"docs/otro.md"},
            12: {"README.md"},
        }
        self.assertEqual(
            file_overlaps(current, others),
            {
                10: ["src/a.php"],
                12: ["README.md"],
            },
        )

    def test_file_overlap_empty_when_independent(self) -> None:
        self.assertEqual(
            file_overlaps({"src/a.php"}, {2: {"src/b.php"}, 3: {"src/c.php"}}),
            {},
        )


if __name__ == "__main__":
    unittest.main()
