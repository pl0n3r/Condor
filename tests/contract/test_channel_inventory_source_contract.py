"""CI-discovered bridge for the exact acceptance target of Condor #863."""

from __future__ import annotations

import unittest

from tests import test_channel_inventory_source as channel_inventory_source


class FuenteInventarioCanalCiContractTests(unittest.TestCase):
    def test_acceptance_is_executed_in_ci(self) -> None:
        result = unittest.TestResult()
        channel_inventory_source.FuenteInventarioCanalTests("test_acceptance").run(result)
        self.assertEqual(result.testsRun, 1, "AC-01 must execute in CI")
        self.assertFalse(result.errors, f"AC-01 errors: {result.errors}")
        self.assertFalse(result.failures, f"AC-01 failures: {result.failures}")
        self.assertFalse(result.skipped, f"AC-01 was skipped: {result.skipped}")
