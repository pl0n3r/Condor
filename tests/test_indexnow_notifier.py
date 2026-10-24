#!/usr/bin/env python3
"""AC-01 #866: IndexNow is tenant-scoped, deduplicated and queue-only."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KEY = ROOT / "src/Application/Seo/IndexNowOperationKey.php"
NOTIFIER = ROOT / "src/Application/Seo/IndexNowNotifier.php"


class IndexNowAdaptadorTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for the acceptance test")
        for source in (KEY, NOTIFIER):
            self.assertTrue(source.is_file(), str(source))
            lint = subprocess.run([php, "-l", str(source)], capture_output=True, text=True,
                                  check=False, timeout=30)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        # No application/vendor bootstrap, no network; the whole transport,
        # tenant-domain resolution and queue are synthetic controlled fakes.
        script = r'''
        require $argv[1];
        require $argv[2];
        $origins = ['a' => 'https://tienda.example.com', 'b' => 'https://otro.example.com'];
        $queued = [];
        $seen = [];
        $delivered = [];
        $failing = false;
        $n = new \App\Application\Seo\IndexNowNotifier(
            static fn(string $tenant): ?string => $origins[$tenant] ?? null,
            static function (array $job) use (&$seen, &$queued): bool {
                if (isset($seen[$job['key']])) return false;
                $seen[$job['key']] = true;
                $queued[] = $job;
                return true;
            },
            static function (string $tenant, string $url, int $attempt) use (&$delivered, &$failing): bool {
                $delivered[] = [$tenant, $url, $attempt];
                return !$failing && $attempt === 2;
            },
        );
        $check = static function (bool $condition, string $message): void {
            if (!$condition) throw new RuntimeException($message);
        };
        $k1 = $n->queuePublication('a', '/productos/mesa', 'rev1', true, true);
        $check(is_string($k1) && strlen($k1) === 64, 'Missing operation key');
        $check($n->queuePublication('a', '/productos/mesa', 'rev1', true, true) === null, 'Duplicate sent');
        $check($n->queuePublication('b', '/productos/mesa', 'rev1', true, true) !== null, 'Tenants collided');
        $check($n->queuePublication('a', '/productos/mesa', 'rev2', true, true) !== null, 'Revision lost');
        $check(count($queued) === 3 && count($delivered) === 0, 'Publication made outbound HTTP');
        foreach (['/admin', '/admin/orders', '/api/private', '/login', '/borrador/item',
                 '/%2e%2e/admin', '//evil.example', '/items?token=abc', '/items#secret',
                 '/productos/../cuenta', '/api%2fprivate'] as $bad) {
            $check($n->queuePublication('a', $bad, 'unique'.$bad, true, true) === null,
                   'Private URL accepted: '.$bad);
        }
        $check($n->queuePublication('a', '/productos/silla', 'rev1', false, true) === null, 'Draft sent');
        $check($n->queuePublication('a', '/productos/silla', 'rev1', true, false) === null, 'Nonindexable sent');
        $check($n->queuePublication('a', '/productos/silla', 'rev1', true, true, true) === null, 'Noindex sent');
        $check($n->queuePublication('unknown', '/productos/silla', 'rev1', true, true) === null, 'Unknown tenant sent');
        $check(count($queued) === 3, 'Rejected path enqueued');
        $check($n->deliver($queued[0]) === true, 'Deferred transport did not succeed');
        $check(count($delivered) === 2, 'Attempts were not bounded/success stopped');
        $bad = $queued[0];
        $bad['tenant'] = 'b';
        $check($n->deliver($bad) === false, 'Cross-tenant replay succeeded');
        $check(count($delivered) === 2, 'Rejected replay reached network');
        $bad = $queued[0];
        $bad['url'] = 'https://evil.example.com/steal';
        $check($n->deliver($bad) === false, 'URL substitution reached network');
        $failing = true;
        $check($n->deliver($queued[1]) === false, 'Failed transport claimed success');
        $check(count($delivered) === 5, 'Unbounded retries');
        echo "AC-01: OK\n";
        '''
        run = subprocess.run([php, "-r", script, str(KEY), str(NOTIFIER)],
                             cwd=ROOT, capture_output=True, text=True, check=False, timeout=30)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        self.assertIn("AC-01: OK", run.stdout)


if __name__ == "__main__":
    unittest.main()
