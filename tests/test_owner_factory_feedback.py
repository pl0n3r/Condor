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


class OwnerFactoryFeedbackTests(unittest.TestCase):
    def test_owner_reads_aggregate_feedback_with_window_coverage_freshness_and_provenance(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;
use App\Application\Commercial\PlatformFactoryFeedback;

$signal = static function (string $subject, string $type, string $direction, int $count): FactoryFeedbackSignal {
    return FactoryFeedbackSignal::fromArray([
        'subject_ref' => $subject,
        'signal_type' => $type,
        'window_start' => '2026-09-01T00:00:00.000000Z',
        'window_end' => '2026-10-01T00:00:00.000000Z',
        'sample_count' => $count,
        'direction' => $direction,
        'evidence_ref' => 'aggregate:' . $type . ':inventory:2026-10',
    ]);
};

$bundle = FactoryFeedbackBundle::fromSignals(
    [
        $signal('capability:inventory', 'usage', 'up', 12),
        $signal('capability:inventory', 'cost', 'flat', 9),
        $signal('capability:inventory', 'demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    3600,
    '2026-10-01T00:30:00.000000Z',
);

print json_encode(PlatformFactoryFeedback::read(true, [$bundle]), JSON_THROW_ON_ERROR);
"""
        )

        self.assertEqual("valid", observed["status"])
        self.assertIsNone(observed["reason"])
        self.assertEqual(1, len(observed["feedback"]))

        feedback = observed["feedback"][0]
        self.assertEqual("capability:inventory", feedback["subject_ref"])
        self.assertEqual(
            {
                "start": "2026-09-01T00:00:00.000000Z",
                "end": "2026-10-01T00:00:00.000000Z",
            },
            feedback["source_window"],
        )
        self.assertEqual(
            {"present": ["usage", "cost", "demand"], "missing": []},
            feedback["coverage"],
        )
        self.assertEqual("fresh", feedback["freshness"]["status"])
        self.assertEqual(
            "aggregate:usage:inventory:2026-10",
            feedback["provenance"]["usage"],
        )
        for forbidden in ("tenant_ref", "customer_ref", "user_ref", "payload"):
            self.assertNotIn(forbidden, feedback)

    def test_non_owner_stale_or_incoherent_feedback_fails_closed_without_actions(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;
use App\Application\Commercial\PlatformFactoryFeedback;

$signal = static function (string $type, string $direction, int $count): FactoryFeedbackSignal {
    return FactoryFeedbackSignal::fromArray([
        'subject_ref' => 'capability:inventory',
        'signal_type' => $type,
        'window_start' => '2026-09-01T00:00:00.000000Z',
        'window_end' => '2026-10-01T00:00:00.000000Z',
        'sample_count' => $count,
        'direction' => $direction,
        'evidence_ref' => 'aggregate:' . $type . ':inventory:2026-10',
    ]);
};

$valid = FactoryFeedbackBundle::fromSignals(
    [
        $signal('usage', 'up', 12),
        $signal('cost', 'flat', 9),
        $signal('demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    3600,
    '2026-10-01T00:30:00.000000Z',
);

$stale = FactoryFeedbackBundle::fromSignals(
    [
        $signal('usage', 'up', 12),
        $signal('cost', 'flat', 9),
        $signal('demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    60,
    '2026-10-01T00:30:00.000000Z',
);

$cases = [
    'non_owner' => PlatformFactoryFeedback::read(false, [$valid]),
    'stale' => PlatformFactoryFeedback::read(true, [$stale]),
    'duplicate' => PlatformFactoryFeedback::read(true, [$valid, $valid]),
    'invalid' => PlatformFactoryFeedback::read(true, [$valid, 'synthetic']),
    'empty' => PlatformFactoryFeedback::read(true, []),
];

$out = [];
foreach ($cases as $name => $result) {
    $out[$name] = [
        'status' => $result['status'],
        'reason' => $result['reason'],
        'feedback' => $result['feedback'],
        'forbidden' => array_values(array_intersect(
            ['score', 'rank', 'priority', 'action', 'recommendation', 'work_item', 'issue'],
            array_keys($result),
        )),
    ];
}

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        for name, result in observed.items():
            self.assertEqual("unavailable", result["status"], name)
            self.assertIsInstance(result["reason"], str, name)
            self.assertIsNone(result["feedback"], name)
            self.assertEqual([], result["forbidden"], name)


if __name__ == "__main__":
    unittest.main()
