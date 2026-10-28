#!/usr/bin/env python3
"""#1070: fake-only SaaS billing, executed against native PHP domain contracts."""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = (
    "src/Application/Commercial/BillingAdapter.php",
    "src/Application/Commercial/BillingRequest.php",
    "src/Application/Commercial/BillingResult.php",
    "src/Application/Commercial/Billing/BillingAdapter.php",
    "src/Application/Commercial/Billing/FakeBillingProvider.php",
)


class SaasTests(unittest.TestCase):
    def php(self, code: str) -> object:
        command = "foreach (array_slice($argv, 1) as $path) { require_once $path; } " + code
        response = subprocess.run(
            ["php", "-r", command, *(str(ROOT / name) for name in PHP)],
            cwd=ROOT, text=True, capture_output=True, check=False, timeout=25,
        )
        self.assertEqual(0, response.returncode, response.stdout + response.stderr)
        return json.loads(response.stdout)

    def test_aislamiento_por_empresa(self) -> None:
        result = self.php(r"""
use App\Application\Commercial\BillingRequest as Req;
use App\Application\Commercial\Billing\FakeBillingProvider as Fake;
$f = new Fake();
$a = new Req('fixture-tenant-a','fixture-subscription-a','fixture-quote-a',30000,'COP','fixture-key-one');
$b = new Req('fixture-tenant-b','fixture-subscription-b','fixture-quote-b',30000,'COP','fixture-key-one');
$x = $f->charge($a,'fixture-actor-owner',true);
$y = $f->charge($b,'fixture-actor-owner',true);
$denied = false;
try { $f->reconcile('fixture-tenant-b',$x->providerRef(),'fixture-actor-owner',true); }
catch (\DomainException $e) { $denied = true; }
$logA = $f->auditForTenant('fixture-tenant-a','fixture-actor-owner',true);
$logB = $f->auditForTenant('fixture-tenant-b','fixture-actor-owner',true);
echo json_encode([$x->outcome(),$y->outcome(),$x->providerRef() !== $y->providerRef(),
                  $denied,count($logA),count($logB),$logA[0]['tenant_ref'],$logB[0]['tenant_ref']]);
""")
        self.assertEqual(
            ["prepared", "prepared", True, True, 1, 1, "fixture-tenant-a", "fixture-tenant-b"], result,
        )

    def test_auditoria_y_permisos(self) -> None:
        result = self.php(r"""
use App\Application\Commercial\BillingRequest as Req;
use App\Application\Commercial\Billing\FakeBillingProvider as Fake;
$f = new Fake();
$r = new Req('fixture-tenant-a','fixture-subscription-a','fixture-quote-a',45000,'COP','fixture-key-charge');
$denied = [];
try { $f->charge($r,'fixture-actor-owner',false); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
$charge = $f->charge($r,'fixture-actor-owner',true);
$repeat = $charge = $f->charge($r,'fixture-actor-owner',true);
$ref = $charge->providerRef();
$event = ['tenant_ref'=>'fixture-tenant-a','charge_ref'=>$ref,
          'event_ref'=>'fixture-event-one','outcome'=>'accepted'];
try { $f->receiveWebhook($event,'fixture-actor-owner',false); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
$web = $f->receiveWebhook($event,'fixture-actor-reviewer',true);
$again = $f->receiveWebhook($event,'fixture-actor-reviewer',true);
$reconciled = $f->reconcile('fixture-tenant-a',$ref,'fixture-actor-owner',true);
try { $f->refund('fixture-tenant-a',$ref,10000,'fixture-key-refund','fixture-actor-owner',false); $denied[] = false; }
catch (\DomainException $e) { $denied[] = true; }
$refund = $f->refund('fixture-tenant-a',$ref,10000,'fixture-key-refund','fixture-actor-owner',true);
$repeatRefund = $f->refund('fixture-tenant-a',$ref,10000,'fixture-key-refund','fixture-actor-owner',true);
$audit = $f->auditForTenant('fixture-tenant-a','fixture-actor-owner',true);
echo json_encode([$denied,$charge->outcome(),$repeat->toArray() === $charge->toArray(),
                  $web->outcome(),$again->toArray() === $web->toArray(),$reconciled->outcome(),
                  $refund->outcome(),$repeatRefund->toArray() === $refund->toArray(),
                  count($audit),array_column($audit,'action'),array_column($audit,'actor_ref')]);
""")
        self.assertEqual([True] * 3, result[0])
        self.assertEqual(["prepared", True, "accepted", True, "accepted", "accepted", True, 3], result[1:9])
        self.assertEqual(["charge_prepared", "webhook_accepted", "refund_accepted"], result[9])
        self.assertEqual(["fixture-actor-owner", "fixture-actor-reviewer", "fixture-actor-owner"], result[10])

    def test_sin_datos_reales(self) -> None:
        result = self.php(r"""
use App\Application\Commercial\BillingRequest as Req;
use App\Application\Commercial\Billing\FakeBillingProvider as Fake;
$f = new Fake();
$cases = [
 ['Empresa SAS','fixture-subscription-a','fixture-quote-a','fixture-key-a','fixture-actor-owner'],
 ['fixture-tenant-a','someone@example.com','fixture-quote-a','fixture-key-b','fixture-actor-owner'],
 ['fixture-tenant-a','fixture-subscription-a','NIT900123','fixture-key-c','fixture-actor-owner'],
 ['fixture-tenant-a','fixture-subscription-a','fixture-quote-a','card:4111111111111111','fixture-actor-owner'],
 ['fixture-tenant-a','fixture-subscription-a','fixture-quote-a','fixture-key-e','someone@company.co'],
];
$out = [];
foreach ($cases as [$tenant,$subscription,$quote,$key,$actor]) {
    try { $f->charge(new Req($tenant,$subscription,$quote,200,'COP',$key),$actor,true); $out[] = false; }
    catch (\DomainException $e) { $out[] = true; }
}
$denied = false;
try {
    $f->receiveWebhook(['tenant_ref'=>'fixture-tenant-a','charge_ref'=>'fixture-charge-test',
     'event_ref'=>'fixture-event-test','outcome'=>'accepted','card'=>'4111111111111111'],
     'fixture-actor-owner',true);
} catch (\DomainException $e) { $denied = true; }
echo json_encode([$out,$denied,count($f->auditForTenant('fixture-tenant-a','fixture-actor-owner',true))]);
""")
        self.assertEqual([[True] * 5, True, 0], result)
        provider = (ROOT / PHP[-1]).read_text(encoding="utf-8")
        for forbidden in ("curl_exec", "HttpClient", "payment_intent", "->flush("):
            self.assertNotIn(forbidden, provider)

    def test_fail_closed(self) -> None:
        result = self.php(r"""
use App\Application\Commercial\BillingRequest as Req;
use App\Application\Commercial\Billing\FakeBillingProvider as Fake;
$f = new Fake();
$r = new Req('fixture-tenant-a','fixture-subscription-a','fixture-quote-a',20000,'COP','fixture-key-charge');
$fail = [];
try { $f->submit($r); $fail[] = false; } catch (\DomainException $e) { $fail[] = true; }
$f->charge($r,'fixture-actor-owner',true);
$ref = $charge->providerRef();
try { $f->reconcile('fixture-tenant-a',$ref,'fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->charge(new Req('fixture-tenant-a','fixture-subscription-a','fixture-quote-a',30000,'COP','fixture-key-charge'),'fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->refund('fixture-tenant-a',$ref,500,'fixture-key-refund','fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->receiveWebhook(['tenant_ref'=>'fixture-tenant-b','charge_ref'=>$ref,'event_ref'=>'fixture-event-other','outcome'=>'accepted'],'fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
$event=['tenant_ref'=>'fixture-tenant-a','charge_ref'=>$ref,'event_ref'=>'fixture-event-ok','outcome'=>'accepted'];
$f->receiveWebhook($event,'fixture-actor-owner',true);
try { $f->receiveWebhook([...$event,'outcome'=>'rejected'],'fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->refund('fixture-tenant-a',$ref,20001,'fixture-key-over','fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->refund('fixture-tenant-b',$ref,100,'fixture-key-other','fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
try { $f->receiveWebhook(['tenant_ref'=>'fixture-tenant-a','charge_ref'=>$ref,'event_ref'=>'fixture-event-new','outcome'=>'rejected'],'fixture-actor-owner',true); $fail[] = false; }
catch (\DomainException $e) { $fail[] = true; }
echo json_encode([$fail,count($f->auditForTenant('fixture-tenant-a','fixture-actor-owner',true))]);
""")
        self.assertEqual([[True] * 9, 2], result)


if __name__ == "__main__":
    unittest.main()
