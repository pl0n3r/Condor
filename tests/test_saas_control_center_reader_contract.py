"""Executable offline acceptance for Condor #640. No DB, network, or secrets."""
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'src/Application/Commercial/SaasControlCenterReader.php'
SETUP = r'''
require getcwd().'/src/Application/Commercial/SaasControlCenterReader.php';
use App\Application\Commercial\SaasControlCenterReader;
$catalog = fn($tenant) => [
    'tenant_id'=>$tenant,
    'plan'=>['tenant_id'=>$tenant,'key'=>'standard','version'=>'v1','currency'=>'COP','price_minor'=>12500],
    'add_ons'=>[['tenant_id'=>$tenant,'key'=>'reports','price_minor'=>2500,'active'=>true]],
];
$usage = fn($tenant) => [['tenant_id'=>$tenant,'metric'=>'seats','used'=>3,'limit'=>10]];
$ents = fn($tenant) => [['tenant_id'=>$tenant,'key'=>'reports','allowed'=>true]];
'''


def run_php(body):
    return subprocess.run(
        ['php', '-d', 'display_errors=stderr', '-r', SETUP + body],
        cwd=ROOT, capture_output=True, text=True, timeout=10, check=False,
    )


class SaasControlCenterReaderContractTests(unittest.TestCase):
    def test_projection_has_plan_addons_usage_and_entitlements(self):
        result = run_php(r'''
$r = new SaasControlCenterReader($catalog,$usage,$ents);
$s = $r->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a');
assert($s['state']==='ready');
if ($s['state']!=='ready' || $s['plan']['key']!=='standard'
 || $s['usage'][0]['used']!==3 || $s['usage'][0]['limit']!==10
 || $s['add_ons'][0]['key']!=='reports' || $s['entitlements'][0]['allowed']!==true) exit(8);
$empty = new SaasControlCenterReader(fn($t)=>null, $usage, $ents);
$x = $empty->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a');
if ($x['state']!=='empty' || $x['plan']!==null || $x['usage']!==[]) exit(9);
''')
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_catalog_prices_are_injected_not_hardcoded(self):
        result = run_php(r'''
$r = new SaasControlCenterReader($catalog,$usage,$ents);
$a = $r->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a');
$other = fn($t)=>['tenant_id'=>$t,
'plan'=>['tenant_id'=>$t,'key'=>'standard','version'=>'v1','currency'=>'COP','price_minor'=>98765],
'add_ons'=>[['tenant_id'=>$t,'key'=>'reports','price_minor'=>3333,'active'=>true]]];
$b = (new SaasControlCenterReader($other,$usage,$ents))->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a');
if ($a['plan']['price_minor']!==12500 || $b['plan']['price_minor']!==98765
 || $b['add_ons'][0]['price_minor']!==3333) exit(12);
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('12500', SOURCE.read_text())
        self.assertNotIn('98765', SOURCE.read_text())

    def test_unauthorized_or_cross_tenant_calls_fail_closed(self):
        result = run_php(r'''
$hits=0;
$spy = function($tenant) use (&$hits) {$hits++; throw new RuntimeException('private fixture');};
$r = new SaasControlCenterReader($spy,$spy,$spy);
foreach ([['ROLE_USER','tenant-a','tenant-a'],['ROLE_PLATFORM_OWNER','tenant-b','tenant-a'],
['ROLE_PLATFORM_OWNER','../invalid','../invalid']] as $args) {
 try {$r->read(...$args); exit(21);} catch (DomainException $e) {}
}
if ($hits!==0) exit(22);
$wrong = fn($tenant)=>['tenant_id'=>'tenant-b','plan'=>[], 'add_ons'=>[]];
try {(new SaasControlCenterReader($wrong,$usage,$ents))->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a'); exit(23);} catch (DomainException $e) {}
$leakUsage = fn($t) => [['tenant_id'=>'tenant-b','metric'=>'seats','used'=>1,'limit'=>5]];
try {(new SaasControlCenterReader($catalog,$leakUsage,$ents))->read('ROLE_PLATFORM_OWNER','tenant-a','tenant-a'); exit(24);} catch (DomainException $e) {}
''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('private fixture', result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
