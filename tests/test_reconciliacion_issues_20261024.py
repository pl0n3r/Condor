"""Contract acceptance for Condor #871: immutable, linked, non-destructive snapshot."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "docs" / "reconciliacion-issues-20261024.md"
EXPECTED_OPEN = {1, 265, 322, 449, 616, 718, 724, 725, 727, 802, 840, 844, 845, 846, 849, 850, 851, 852, 853, 854, 855, 856, 857, 858, 859, 860, 861, 862, 863, 864, 865, 866, 867, 868, 869, 870, 871}
TABLE_START = "<!-- issues-table-start -->"
TABLE_END = "<!-- issues-table-end -->"
ROW = re.compile(
    r"^\| \[#(?P<id>\d+)\]\(https://github\.com/pl0n3r/Condor/issues/(?P=id)\)"
    r" \| (?P<title>[^|]+) \| (?P<status>[^|]+) \| \*\*(?P<decision>ACTUALIZAR|MANTENER|CERRAR)\*\*"
    r" \| (?P<reason>[^|]+) \|$"
)

def validate_snapshot(document: str) -> list[dict[str, str]]:
    if document.count(TABLE_START) != 1 or document.count(TABLE_END) != 1:
        raise ValueError("unbounded table")
    part = document.split(TABLE_START, 1)[1].split(TABLE_END, 1)[0]
    lines = [line for line in part.splitlines() if line.startswith("| [#")]
    found: list[dict[str, str]] = []
    for line in lines:
        match = ROW.fullmatch(line)
        if not match:
            raise ValueError("invalid table row")
        item = match.groupdict()
        if len(item["title"].strip()) < 6 or len(item["status"].strip()) < 4:
            raise ValueError("missing title or state")
        if len(item["reason"].strip()) < 24:
            raise ValueError("missing reason")
        if not re.search(
            r"\[Evidencia\]\(https://github\.com/pl0n3r/Condor/(?:issues|pull)/\d+\)",
            item["reason"],
        ):
            raise ValueError("missing direct evidence link")
        if item["decision"] == "CERRAR":
            raise ValueError("closure requires postmerge SHA and accepted CI, not a snapshot")
        found.append(item)
    nums = [int(item["id"]) for item in found]
    if len(nums) != len(set(nums)) or set(nums) != EXPECTED_OPEN:
        raise ValueError("missing, extra or duplicate issue")
    return found

class ReconciliacionIssuesTests(unittest.TestCase):
    def test_acceptance(self):
        text = FILE.read_text(encoding="utf-8")
        self.assertIn("ebab5730501e44356c27b17e8638b3a7e3d5bc34", text)
        self.assertIn("Snapshot fechado", text)
        self.assertIn("opción B", text)
        self.assertIn("#449", text)
        self.assertIn("#840", text)
        self.assertIn("#877", text)
        rows = validate_snapshot(text)
        self.assertEqual(37, len(rows))
        self.assertFalse(any(row["decision"] == "CERRAR" for row in rows))
        self.assertTrue(any(row["decision"] == "ACTUALIZAR" for row in rows))
        self.assertTrue(any(row["decision"] == "MANTENER" for row in rows))

    def test_missing_or_duplicate_row_rejected(self):
        text = FILE.read_text(encoding="utf-8")
        match = next(line for line in text.splitlines() if line.startswith("| [#871]"))
        with self.assertRaises(ValueError):
            validate_snapshot(text.replace(match, ""))
        with self.assertRaises(ValueError):
            validate_snapshot(text.replace(match, match + "\n" + match))

    def test_unlinked_evidence_rejected(self):
        text = FILE.read_text(encoding="utf-8")
        self.assertIn("[Evidencia](", text)
        with self.assertRaises(ValueError):
            validate_snapshot(text.replace("[Evidencia](", "[Sin fuente](", 1))


if __name__ == "__main__":
    unittest.main()
