#!/usr/bin/env python3
from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.ci_retry import is_transient_failure, run_with_retry


class RetryTests(unittest.TestCase):
    def test_retries_transient_command_until_success(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            counter = Path(tmp) / "counter"
            code = (
                "from pathlib import Path; import sys; "
                f"p=Path({str(counter)!r}); "
                "n=int(p.read_text() if p.exists() else '0')+1; "
                "p.write_text(str(n)); sys.exit(0 if n >= 2 else 75)"
            )
            with patch("scripts.ci_retry.time.sleep") as sleep:
                result = run_with_retry(
                    [sys.executable, "-c", code],
                    attempts=3,
                    base_delay=1,
                    label="prueba",
                )
            self.assertEqual(result, 0)
            self.assertEqual(counter.read_text(), "2")
            sleep.assert_called_once_with(1)

    def test_retries_transient_text_signal(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            counter = Path(tmp) / "counter"
            code = (
                "from pathlib import Path; import sys; "
                f"p=Path({str(counter)!r}); "
                "n=int(p.read_text() if p.exists() else '0')+1; "
                "p.write_text(str(n)); "
                "print('connection reset by peer' if n < 2 else 'ok'); "
                "sys.exit(0 if n >= 2 else 1)"
            )
            with patch("scripts.ci_retry.time.sleep") as sleep:
                result = run_with_retry(
                    [sys.executable, "-c", code],
                    attempts=3,
                    base_delay=1,
                    label="red",
                )
            self.assertEqual(result, 0)
            self.assertEqual(counter.read_text(), "2")
            sleep.assert_called_once_with(1)

    def test_deterministic_failure_is_not_retried(self) -> None:
        with patch("scripts.ci_retry.time.sleep") as sleep:
            result = run_with_retry(
                [
                    sys.executable,
                    "-c",
                    "import sys; print('lock file is invalid'); sys.exit(9)",
                ],
                attempts=3,
                base_delay=1,
                label="determinista",
            )
        self.assertEqual(result, 9)
        sleep.assert_not_called()

    def test_dns_and_tls_failures_are_not_assumed_transient(self) -> None:
        self.assertFalse(is_transient_failure(1, "Could not resolve host: registry"))
        self.assertFalse(is_transient_failure(1, "certificate verify failed"))
        self.assertTrue(is_transient_failure(1, "HTTP 503 Service Unavailable"))

    def test_rejects_unbounded_attempts(self) -> None:
        with self.assertRaises(ValueError):
            run_with_retry(
                [sys.executable, "-c", "pass"],
                attempts=99,
                base_delay=0,
                label="prueba",
            )


if __name__ == "__main__":
    unittest.main()
