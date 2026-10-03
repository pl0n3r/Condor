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


class FactoryFeedbackBundleTests(unittest.TestCase):
    def test_bundle_reports_coverage_provenance_and_freshness_for_coherent_aggregate_signals(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;

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

$bundle = FactoryFeedbackBundle::fromSignals(
    [
        $signal('usage', 'up', 12),
        $signal('cost', 'flat', 9),
        $signal('demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    3600,
    '2026-10-01T00:30:00.000000Z',
);

print json_encode($bundle->toArray(), JSON_THROW_ON_ERROR);
"""
        )

        self.assertTrue(observed["available"])
        self.assertTrue(observed["fresh"])
        self.assertEqual(
            {"present": ["usage", "cost", "demand"], "missing": []},
            observed["coverage"],
        )
        self.assertEqual(
            {
                "usage": "aggregate:usage:inventory:2026-10",
                "cost": "aggregate:cost:inventory:2026-10",
                "demand": "aggregate:demand:inventory:2026-10",
            },
            observed["provenance"],
        )
        for forbidden in ("score", "rank", "priority", "action", "recommendation"):
            self.assertNotIn(forbidden, observed)

    def test_mixed_duplicate_stale_or_incomplete_evidence_fails_closed_without_ranking(self) -> None:
        observed = run_php(
            r"""
require 'vendor/autoload.php';

use App\Application\Commercial\FactoryFeedbackBundle;
use App\Application\Commercial\FactoryFeedbackSignal;

$signal = static function (
    string $type,
    string $direction,
    int $count,
    string $subject = 'capability:inventory',
): FactoryFeedbackSignal {
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

$out = [];

foreach ([
    'duplicate' => [$signal('usage', 'up', 12), $signal('usage', 'flat', 8)],
    'mixed' => [
        $signal('usage', 'up', 12),
        $signal('cost', 'flat', 9, 'product:commercial'),
    ],
] as $name => $signals) {
    try {
        FactoryFeedbackBundle::fromSignals(
            $signals,
            '2026-10-01T00:05:00.000000Z',
            3600,
            '2026-10-01T00:30:00.000000Z',
        );
        $out[$name] = 'accepted';
    } catch (DomainException $exception) {
        $out[$name] = $exception->getMessage();
    }
}

$incomplete = FactoryFeedbackBundle::fromSignals(
    [$signal('usage', 'up', 12)],
    '2026-10-01T00:05:00.000000Z',
    3600,
    '2026-10-01T00:30:00.000000Z',
)->toArray();

$stale = FactoryFeedbackBundle::fromSignals(
    [
        $signal('usage', 'up', 12),
        $signal('cost', 'flat', 9),
        $signal('demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    60,
    '2026-10-01T00:30:00.000000Z',
)->toArray();

$unknown = FactoryFeedbackBundle::fromSignals(
    [
        $signal('usage', 'unknown', 12),
        $signal('cost', 'flat', 9),
        $signal('demand', 'up', 7),
    ],
    '2026-10-01T00:05:00.000000Z',
    3600,
    '2026-10-01T00:30:00.000000Z',
)->toArray();

$out['incomplete_available'] = $incomplete['available'];
$out['incomplete_missing'] = $incomplete['coverage']['missing'];
$out['stale_available'] = $stale['available'];
$out['stale_fresh'] = $stale['fresh'];
$out['unknown_available'] = $unknown['available'];
$out['forbidden_present'] = array_values(array_intersect(
    ['score', 'rank', 'priority', 'action', 'recommendation'],
    array_keys($unknown),
));

print json_encode($out, JSON_THROW_ON_ERROR);
"""
        )

        self.assertNotEqual("accepted", observed["duplicate"])
        self.assertNotEqual("accepted", observed["mixed"])
        self.assertFalse(observed["incomplete_available"])
        self.assertEqual(["cost", "demand"], observed["incomplete_missing"])
        self.assertFalse(observed["stale_available"])
        self.assertFalse(observed["stale_fresh"])
        self.assertFalse(observed["unknown_available"])
        self.assertEqual([], observed["forbidden_present"])


if __name__ == "__main__":
    unittest.main()
