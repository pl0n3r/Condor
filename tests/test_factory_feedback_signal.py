#!/usr/bin/env python3
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run_php(script: str) -> dict[str, object]:
    raw = subprocess.check_output(
        ["php", "-r", script],
        cwd=ROOT,
        text=True,
        stderr=subprocess.STDOUT,
    )
    return json.loads(raw)


class FactoryFeedbackSignalTests(unittest.TestCase):
    def test_signal_keeps_only_aggregate_subject_type_window_count_direction_and_provenance(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackSignal;

$signal = FactoryFeedbackSignal::fromArray([
    'subject_ref' => 'capability:inventory',
    'signal_type' => 'usage',
    'window_start' => '2026-09-01T00:00:00.000000Z',
    'window_end' => '2026-10-01T00:00:00.000000Z',
    'sample_count' => 12,
    'direction' => 'up',
    'evidence_ref' => 'aggregate:usage:inventory:2026-10',
]);

print json_encode($signal->toArray(), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual(
            [
                "subject_ref",
                "signal_type",
                "window_start",
                "window_end",
                "sample_count",
                "direction",
                "evidence_ref",
            ],
            list(observed.keys()),
        )
        self.assertEqual("capability:inventory", observed["subject_ref"])
        self.assertEqual("usage", observed["signal_type"])
        self.assertEqual(12, observed["sample_count"])
        self.assertEqual("up", observed["direction"])
        self.assertEqual("aggregate:usage:inventory:2026-10", observed["evidence_ref"])

    def test_individual_sensitive_small_cohort_or_free_form_signal_fails_closed(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackSignal;

$valid = [
    'subject_ref' => 'product:commercial',
    'signal_type' => 'demand',
    'window_start' => '2026-09-01T00:00:00.000000Z',
    'window_end' => '2026-10-01T00:00:00.000000Z',
    'sample_count' => 7,
    'direction' => 'flat',
    'evidence_ref' => 'aggregate:demand:commercial:2026-10',
];

$cases = [
    'individual_ref' => [...$valid, 'user_ref' => 'synthetic'],
    'tenant_ref' => [...$valid, 'tenant_ref' => 'synthetic'],
    'free_form' => [...$valid, 'note' => 'synthetic'],
    'small_cohort' => [...$valid, 'sample_count' => 2],
    'individual_subject' => [...$valid, 'subject_ref' => 'user:synthetic'],
    'non_aggregate_evidence' => [...$valid, 'evidence_ref' => 'event:synthetic'],
    'unknown_type' => [...$valid, 'signal_type' => 'individual'],
    'ranking_direction' => [...$valid, 'direction' => 'priority'],
    'invalid_window' => [...$valid, 'window_end' => $valid['window_start']],
];

$out = [];
foreach ($cases as $name => $payload) {
    try {
        FactoryFeedbackSignal::fromArray($payload);
        $out[$name] = 'accepted';
    } catch (DomainException $exception) {
        $out[$name] = $exception->getMessage();
    }
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for name, outcome in observed.items():
            self.assertNotEqual("accepted", outcome, name)


if __name__ == "__main__":
    unittest.main()
