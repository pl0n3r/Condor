"""#1122: real PHP domain+DBAL repository exercised with synthetic test double.

No external DB or payment provider is contacted, nor real migration executed.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENTITY = ROOT / "src/Domain/Commercial/Entity/ManualCommercialDecision.php"
REPO = ROOT / "src/Infrastructure/Persistence/ManualCommercialDecisionRepository.php"
MIGRATION = ROOT / "migrations/Version20261202120000.php"

BOOT = r'''
namespace Doctrine\DBAL {
    class Connection {
        public array $rows = [];
        public array $tenants = ['fixture-alpha', 'fixture-beta'];
        public array $plans = [];
        public bool $quoted = false;
        public function transactional(callable $callback): mixed { return $callback(); }
        public function fetchOne(string $sql, array $args): mixed {
            if (str_contains($sql,'condor_tenant')) {
                return in_array($args[0], $this->tenants, true) ? $args[0] : false;
            }
            if (str_contains($sql, 'plan_version_vertical')) {
                return $args[0] === str_repeat('P',26) && $args[1] === str_repeat('V',26) ? $args[1] : false;
            }
            return false;
        }
        public function fetchAssociative(string $sql, array $args): array|false {
            if (str_contains($sql, 'FROM condor_commercial_subscription ')) {
                if ($args[1] !== 'fixture-alpha' && $args[1] !== 'fixture-beta') { return false; }
                if ($args[0] !== str_repeat('S',26)) { return false; }
                return ['id'=>$args[0], 'tenant_id'=>$args[1]];
            }
            if (str_contains($sql, 'FROM condor_commercial_plan_version ')) {
                return $args[0] === str_repeat('P',26)
                    ? ['id'=>$args[0], 'monthly_amount'=>'120000', 'quote_required'=>$this->quoted ? 1 : 0]
                    : false;
            }
            if (str_contains($sql, 'FROM condor_commercial_manual_decision ')) {
                return $this->rows[$args[0].'|'.$args[1]] ?? false;
            }
            return false;
        }
        public function insert(string $table, array $data): int {
            if ($table !== 'condor_commercial_manual_decision') { throw new \DomainException('Wrong table'); }
            $key = $data['tenant_id'].'|'.$data['event_key'];
            if (isset($this->rows[$key])) { throw new \DomainException('No duplicate insert'); }
            $this->rows[$key] = $data;
            return 1;
        }
        public function fetchAllAssociative(string $sql, array $args): array {
            if (!str_contains($sql,'WHERE tenant_id = ?')) { throw new \DomainException('Unscoped read'); }
            return array_values(array_filter($this->rows,
                static fn(array $row): bool => $row['tenant_id'] === $args[0]));
        }
    }
}
namespace {
require $argv[1];
require $argv[2];
use App\Domain\Commercial\Entity\ManualCommercialDecision;
use App\Infrastructure\Persistence\ManualCommercialDecisionRepository;
$db = new \Doctrine\DBAL\Connection();
$repo = new ManualCommercialDecisionRepository($db);
function make(string $tenant='fixture-alpha', string $event='fixture-event-one',
    string $subscription='', int $discount=1000, string $reason='fixture-manual-approval',
    string $approver=''): ManualCommercialDecision {
    $actor = str_repeat('A',26);
    return new ManualCommercialDecision(
        $tenant, $subscription ?: str_repeat('S',26), str_repeat('P',26), str_repeat('V',26),
        $event, $actor, $approver ?: $actor, $reason, $discount,
        '2026-10-01T12:00:00Z', '2026-11-01T12:00:00Z',
        '2026-12-01T12:00:00Z', 'MANUAL_UNVERIFIED',
    );
}
function denied(callable $action): bool {
    try { $action(); return false; } catch (\DomainException $e) { return true; }
}
'''


def php(code: str) -> dict:
    process = subprocess.run(
        ["php", "-n", "-d", "allow_url_fopen=0", "-r", BOOT + code + "\n}",
         str(ENTITY), str(REPO)], cwd=ROOT, capture_output=True, text=True, timeout=20,
    )
    if process.returncode:
        raise AssertionError(process.stdout + process.stderr)
    return json.loads(process.stdout)


class ManualTermsTests(unittest.TestCase):
    def test_aislamiento_por_empresa(self):
        data = php(r'''
$a=$repo->record(make(), 'fixture-alpha', str_repeat('A',26), true);
$b=$repo->record(make('fixture-beta'), 'fixture-beta', str_repeat('A',26), true);
$first=$repo->forTenant('fixture-alpha', str_repeat('A',26), true);
$second=$repo->forTenant('fixture-beta', str_repeat('A',26), true);
echo json_encode(['created'=>$a['recorded']&&$b['recorded'],
    'alpha'=>count($first), 'beta'=>count($second),
    'own'=>$first[0]['tenant_id'], 'other'=>$second[0]['tenant_id'],
    'denied'=>denied(fn()=> $repo->record(make(), 'fixture-beta', str_repeat('A',26), true))]);
''')
        self.assertTrue(data["created"])
        self.assertEqual((data["alpha"], data["beta"]), (1, 1))
        self.assertEqual(data["own"], "fixture-alpha")
        self.assertEqual(data["other"], "fixture-beta")
        self.assertTrue(data["denied"])

    def test_auditoria_y_permisos(self):
        data = php(r'''
$denied = denied(fn()=> $repo->record(make(), 'fixture-alpha', str_repeat('A',26), false));
$actor = denied(fn()=> $repo->record(make(), 'fixture-alpha', str_repeat('B',26), true));
$approval = denied(fn()=> make('fixture-alpha', 'fixture-another', '', 1000,
    'fixture-reason', str_repeat('B',26)));
$once = $repo->record(make(), 'fixture-alpha', str_repeat('A',26), true);
$again = $repo->record(make(), 'fixture-alpha', str_repeat('A',26), true);
$rows = $repo->forTenant('fixture-alpha', str_repeat('A',26), true);
echo json_encode(['denied'=>$denied, 'actor'=>$actor, 'approval'=>$approval,
    'once'=>$once['recorded'], 'again'=>$again['recorded'],
    'fingerprint'=>$once['fingerprint']===$again['fingerprint'],
    'price'=>$once['effective_monthly_amount'],
    'approved'=>$db->rows['fixture-alpha|fixture-event-one']['approver_ref'],
    'rows'=>count($rows)]);
''')
        self.assertTrue(data["denied"] and data["actor"] and data["approval"])
        self.assertTrue(data["once"])
        self.assertFalse(data["again"])
        self.assertTrue(data["fingerprint"])
        self.assertEqual(data["price"], 108000)
        self.assertEqual(data["approved"], "A" * 26)
        self.assertEqual(data["rows"], 1)

    def test_sin_datos_reales(self):
        data = php(r'''
$badTenant=denied(fn()=> make('person@example.com'));
$badEvent=denied(fn()=> make('fixture-alpha','3012345678'));
$badReason=denied(fn()=> make('fixture-alpha','fixture-good','',1000,'person@example.com'));
$badPayment=denied(fn()=> new ManualCommercialDecision(
    'fixture-alpha',str_repeat('S',26),str_repeat('P',26),str_repeat('V',26),
    'fixture-event-two',str_repeat('A',26),str_repeat('A',26),'fixture-reason',1000,
    '2026-10-01T12:00:00Z','2026-11-01T12:00:00Z','2026-12-01T12:00:00Z','PAID'));
echo json_encode(['all'=>$badTenant&&$badEvent&&$badReason&&$badPayment, 'rows'=>count($db->rows)]);
''')
        self.assertTrue(data["all"])
        self.assertEqual(data["rows"], 0)
        source = ENTITY.read_text()
        self.assertNotIn("curl_exec(", source)
        self.assertNotIn("PDO(", source)

    def test_fail_closed(self):
        data = php(r'''
$unknown = denied(fn()=> $repo->record(make('fixture-alpha','fixture-two',str_repeat('X',26)),
    'fixture-alpha',str_repeat('A',26),true));
$db->quoted=true;
$quoted = denied(fn()=> $repo->record(make(), 'fixture-alpha',str_repeat('A',26),true));
$db->quoted=false;
$initial=$repo->record(make(), 'fixture-alpha',str_repeat('A',26),true);
$changed=denied(fn()=> $repo->record(make('fixture-alpha','fixture-event-one','',2000),
    'fixture-alpha',str_repeat('A',26),true));
$stale=denied(fn()=> new ManualCommercialDecision('fixture-alpha',str_repeat('S',26),
    str_repeat('P',26),str_repeat('V',26),'fixture-event-three',str_repeat('A',26),
    str_repeat('A',26),'fixture-reason',1000,
    '2026-12-01T12:00:00Z','2026-11-01T12:00:00Z','2026-11-15T12:00:00Z',
    'MANUAL_PENDING'));
echo json_encode(['denied'=>$unknown&&$quoted&&$changed&&$stale,
    'recorded'=>$initial['recorded'],'rows'=>count($db->rows)]);
''')
        self.assertTrue(data["denied"])
        self.assertTrue(data["recorded"])
        self.assertEqual(data["rows"], 1)
        migration = MIGRATION.read_text()
        self.assertIn("UNIQUE INDEX uniq_cmd_tenant_event", migration)
        self.assertIn("ON DELETE RESTRICT", migration)
        self.assertIn("IrreversibleMigration", migration)


if __name__ == "__main__":
    unittest.main()
