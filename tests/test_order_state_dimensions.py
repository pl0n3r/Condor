#!/usr/bin/env python3
"""Issue #858 AC-01: run real PHP transition engine without database/network."""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MACHINE = ROOT / "src/Domain/Orders/OrderStateMachine.php"
SERVICE = ROOT / "src/Application/Orders/OrderStateTransitions.php"

PHP_SCENARIO = r'''
require $argv[1];
require $argv[2];
$m = new App\Domain\Orders\OrderStateMachine();
$s = new App\Application\Orders\OrderStateTransitions($m);
$at = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$deny = static function (callable $cb, string $message) use ($assert): void {
    try { $cb(); } catch (DomainException) { return; }
    throw new RuntimeException($message);
};
$a = $m->initial('tenant-A', 10000);
$assert($a['order'] === 'open' && $a['payment'] === 'pending'
    && $a['fulfillment'] === 'reserved', 'Canonical order defaults');
$assert($m->visibleName('payment', 'pending', ['payment'=>['pending'=>'En espera']]) === 'En espera', 'Tenant label override');
$assert($m->visibleName('payment', 'pending') === 'Pendiente', 'Tenant label leaked');
$deny(fn() => $m->visibleName('payment','pending',['payment'=>['pending'=>'<script>']]), 'Unsafe label');
$deny(fn() => $s->change($a,'fulfillment','consumed','tenant-A','tenant-B','actor-1',$at), 'Tenant isolation');
$deny(fn() => $s->change($a,'fulfillment','consumed','tenant-B','tenant-B','actor-1',$at), 'Forged tenant context');
$deny(fn() => $s->change($a,'order','invalid','tenant-A','tenant-A','actor-1',$at), 'Invalid transition');
$deny(fn() => $s->change($a,'payment','paid','tenant-A','tenant-A','actor-1',$at), 'Unpaid order marked paid');
$deny(fn() => $s->recordPayment($a,2000,false,'tenant-A','tenant-A','actor-1',$at), 'Partial payment without tenant capability');
$a2 = $s->recordPayment($a,2000,true,'tenant-A','tenant-A','actor-1',$at);
$assert($a2['order'] === 'open' && $a2['payment'] === 'pending'
    && $a2['fulfillment'] === 'reserved' && $a2['paid_minor'] === 2000,
    'Payment mutated another dimension');
$assert($s->pendingBalance($a2) === 8000 && $s->pendingBalance($a) === 10000, 'Balance or input mutation');
$assert(count($a2['history']) === 1 && $a2['history'][0]['actor'] === 'actor-1'
    && $a2['history'][0]['dimension'] === 'payment'
    && $a2['history'][0]['from'] === 'pending'
    && $a2['history'][0]['to'] === 'pending'
    && $a2['history'][0]['amount_minor'] === 2000,
    'Partial payment audit missing');
$a3 = $s->recordPayment($a2,8000,true,'tenant-A','tenant-A','actor-2',$at);
$assert($a3['payment'] === 'paid' && $s->pendingBalance($a3) === 0, 'Payment did not settle');
$assert($a3['history'][1]['from'] === 'pending' && $a3['history'][1]['to'] === 'paid', 'Settlement audit missing');
$deny(fn() => $s->recordPayment($a3,1,true,'tenant-A','tenant-A','actor-2',$at), 'Overpayment accepted');
$a4 = $s->change($a3,'fulfillment','consumed','tenant-A','tenant-A','actor-3',$at);
$assert($a4['fulfillment'] === 'consumed' && $a4['order'] === 'open'
    && $a4['payment'] === 'paid', 'Fulfillment changed payment/order');
$assert(count($a4['history']) === 3 && $a4['history'][2]['at'] === '2026-10-24T12:00:00+00:00', 'Trace timestamp');
$deny(fn() => $s->change($a4,'order','cancelled','tenant-A','tenant-A','actor-3',$at), 'Consumed order cancelled');
$deny(fn() => $s->change($a4,'fulfillment','released','tenant-A','tenant-A','actor-3',$at), 'Fulfillment reversed');
$cancelled = $s->change($a,'order','cancelled','tenant-A','tenant-A','actor-4',$at);
$assert($cancelled['order'] === 'cancelled' && $cancelled['fulfillment'] === 'released', 'Atomic cancel/release');
$assert(count($cancelled['history']) === 2 && $cancelled['history'][0]['dimension'] === 'order'
    && $cancelled['history'][1]['dimension'] === 'fulfillment', 'Cancellation audit not atomic');
$deny(fn() => $s->change($cancelled,'fulfillment','consumed','tenant-A','tenant-A','actor-4',$at), 'Cancelled order consumed');
$deny(fn() => $s->recordPayment($cancelled,10000,true,'tenant-A','tenant-A','actor-4',$at), 'Cancelled order charged');
$zero = $m->initial('tenant-A', 0);
$assert($zero['payment'] === 'paid' && $s->pendingBalance($zero) === 0, 'Zero amount order');
$assert(count($a['history']) === 0, 'Original snapshot mutated');
echo json_encode(['ac'=>'OK','steps'=>count($a4['history']),'cancel_audit'=>count($cancelled['history'])]);
'''


class EstadosPedidoPagoCumplimientoTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required")
        for source in (MACHINE, SERVICE):
            self.assertTrue(source.is_file(), str(source))
            result = subprocess.run([php, "-l", str(source)], cwd=ROOT,
                                    text=True, capture_output=True, check=False, timeout=30)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        result = subprocess.run([php, "-r", PHP_SCENARIO, str(MACHINE), str(SERVICE)],
                                cwd=ROOT, text=True, capture_output=True,
                                check=False, timeout=30)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual({"ac": "OK", "steps": 3, "cancel_audit": 2}, json.loads(result.stdout))


if __name__ == "__main__":
    unittest.main()
