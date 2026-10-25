#!/usr/bin/env python3
"""Condor #861: executable PHP delivery policy and branch HTTP gates."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVICE = ROOT / "src/Application/Orders/OrderFulfillmentService.php"
CONTROLLER = ROOT / "src/Http/Controller/OrderFulfillmentController.php"

PHP = r'''
require $argv[1];
use App\Application\Orders\OrderFulfillmentService as S;
$t = new DateTimeImmutable('2026-10-24T12:00:00+00:00');
$check = static function ($ok, $why) { if (!$ok) throw new RuntimeException($why); };
$deny = static function ($fn, $why) { try { $fn(); } catch (DomainException) { return; } throw new RuntimeException($why); };
$a = S::initial('tenant-A');
$deny(fn() => S::initial(''), 'Missing tenant');
$deny(fn() => S::apply($a,'tenant-B','actor-1','configure','shipping',null,$t), 'Cross tenant');
$deny(fn() => S::apply($a,'tenant-A','','configure','shipping',null,$t), 'Missing actor');
$deny(fn() => S::apply($a,'tenant-A','actor-1','configure','invalid',null,$t), 'Invalid method');
$deny(fn() => S::apply($a,'tenant-A','actor-1','configure','pickup','TRK123',$t), 'Pickup guide');
$deny(fn() => S::apply($a,'tenant-A','actor-1','configure','shipping','../secret',$t), 'Unsafe guide');
$s = S::apply($a,'tenant-A','actor-1','configure','shipping','trk-123',$t);
$check($s['method']==='shipping' && $s['status']==='pending' && $s['tracking_number']==='TRK-123', 'Shipping setup');
$check(count($a['history'])===0 && count($s['history'])===1, 'Original mutated');
$deny(fn() => S::apply($s,'tenant-A','actor-1','configure','pickup',null,$t), 'Reconfigure');
$deny(fn() => S::apply($s,'tenant-A','actor-1','transition','delivered',null,$t), 'Skip states');
$b = S::apply($s,'tenant-A','actor-2','transition','shipped',null,$t);
$c = S::apply($b,'tenant-A','actor-3','transition','delivered',null,$t);
$check($c['status']==='delivered' && count($c['history'])===3
 && $c['history'][2]['actor']==='actor-3' && $c['history'][2]['from']==='shipped'
 && $c['history'][2]['at']==='2026-10-24T12:00:00+00:00', 'Audit');
$deny(fn() => S::apply($c,'tenant-A','actor-3','transition','pending',null,$t), 'Reversal');
$p = S::apply($a,'tenant-A','actor-1','configure','pickup',null,$t);
$p = S::apply($p,'tenant-A','actor-1','transition','ready',null,$t);
$p = S::apply($p,'tenant-A','actor-1','transition','collected',null,$t);
$check($p['status']==='collected', 'Pickup');
$n = S::apply($a,'tenant-A','actor-1','configure','none',null,$t);
$n = S::apply($n,'tenant-A','actor-1','transition','completed',null,$t);
$check($n['status']==='completed' && $n['tracking_number']===null, 'No delivery');
$deny(fn() => S::apply($n,'tenant-A','actor-1','transition','completed',null,$t), 'Duplicate');
echo json_encode(['state'=>'OK','events'=>count($c['history'])]);
'''


class CumplimientoEntregaApiTests(unittest.TestCase):
    def test_acceptance(self):
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required")
        for path in (SERVICE, CONTROLLER):
            self.assertTrue(path.is_file(), f"Missing: {path}")
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                  text=True, capture_output=True, timeout=30)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        result = subprocess.run([php, "-r", PHP, str(SERVICE)], cwd=ROOT,
                                text=True, capture_output=True, timeout=30)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual({"state": "OK", "events": 3}, json.loads(result.stdout))
        controller = CONTROLLER.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")
        for needle in ("authorizedBranchScope($branchId, 'orders.view')",
                       "authorizedBranchScope($branchId, 'orders.update')",
                       "requireCsrf($request)", "'tenant' => $tenant",
                       "inventorySource()->branch()", "Cache-Control",
                       "['method', 'tracking_number']", "['status']"):
            self.assertIn(needle, controller)
        for needle in ("PESSIMISTIC_WRITE", "wrapInTransaction(", "new OrderEvent(",
                       "delivery.configured", "delivery.transition", "'leg_id' => 'primary'",
                       "hash_equals(", "STATUS_OPEN", "FULFILLMENT_RELEASED"):
            self.assertIn(needle, service)


if __name__ == "__main__":
    unittest.main()
