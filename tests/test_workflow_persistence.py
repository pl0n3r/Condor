#!/usr/bin/env python3
"""Condor #856: aceptación de persistencia real SQL, con SQLite sintético."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT / "src/Infrastructure/Persistence/WorkflowRepository.php"
MIGRATION = ROOT / "migrations/Version20261024230000.php"

SCENARIO = r'''<?php
declare(strict_types=1);
namespace Doctrine\DBAL {
    final class Connection {
        private \PDO $pdo;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->executeStatement('CREATE TABLE condor_workflow_definition (tenant_id TEXT NOT NULL, workflow_id TEXT NOT NULL, version INTEGER NOT NULL, trigger_name TEXT NOT NULL, conditions_json TEXT NOT NULL, actions_json TEXT NOT NULL, is_active INTEGER NOT NULL, created_by_user_id TEXT NOT NULL, updated_by_user_id TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(tenant_id,workflow_id,version))');
            $this->executeStatement('CREATE TABLE condor_workflow_execution (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, workflow_id TEXT NOT NULL, definition_version INTEGER NOT NULL, actor_user_id TEXT NOT NULL, result TEXT NOT NULL, reason TEXT NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY(tenant_id,workflow_id,definition_version) REFERENCES condor_workflow_definition(tenant_id,workflow_id,version))');
        }
        private function query(string $sql, array $values=[]): \PDOStatement {
            $q = $this->pdo->prepare($sql); $q->execute($values); return $q;
        }
        public function fetchOne(string $sql, array $p=[]): mixed { return $this->query($sql,$p)->fetchColumn(); }
        public function fetchAssociative(string $sql, array $p=[]): array|false { return $this->query($sql,$p)->fetch(\PDO::FETCH_ASSOC); }
        public function fetchAllAssociative(string $sql, array $p=[]): array { return $this->query($sql,$p)->fetchAll(\PDO::FETCH_ASSOC); }
        public function executeStatement(string $sql, array $p=[]): int { return $this->query($sql,$p)->rowCount(); }
        public function insert(string $table, array $values): int {
            if (!in_array($table,['condor_workflow_definition','condor_workflow_execution'],true)) { throw new \RuntimeException('Table'); }
            $keys=array_keys($values);
            return $this->executeStatement('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($values));
        }
        public function transactional(callable $fn): mixed {
            $this->pdo->beginTransaction();
            try { $v=$fn($this); $this->pdo->commit(); return $v; }
            catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        }
    }
}
namespace {
    require_once getcwd().'/src/Domain/Workflow/WorkflowDefinition.php';
    require_once getcwd().'/src/Domain/Workflow/ExecutionTrace.php';
    require_once getcwd().'/src/Domain/Workflow/WorkflowEngine.php';
    require_once getcwd().'/src/Infrastructure/Persistence/WorkflowRepository.php';
    use App\Domain\Workflow\WorkflowDefinition;
    use App\Domain\Workflow\WorkflowEngine;
    use App\Infrastructure\Persistence\WorkflowRepository;
    function check(bool $condition, string $reason): void { if (!$condition) throw new \RuntimeException($reason); }
    function denied(callable $fn, string $reason): void {
        try { $fn(); } catch (\DomainException $e) { return; }
        throw new \RuntimeException('Accepted invalid: '.$reason);
    }
    $db=new \Doctrine\DBAL\Connection();
    $repo=new WorkflowRepository($db);
    $time=new \DateTimeImmutable('2026-10-24T12:00:00+00:00');
    $a=new WorkflowDefinition('tenant-A','stock','inventory.low_stock',[],[['type'=>'inventory.restock']]);
    $b=new WorkflowDefinition('tenant-B','stock','inventory.low_stock',[],[['type'=>'inventory.restock']]);
    $forbidden=new WorkflowDefinition('tenant-A','private','inventory.low_stock',[],[
        ['type'=>'inventory.restock','arguments'=>['private'=>'synthetic_sensitive_marker_856']]
    ]);
    denied(fn()=>$repo->save($forbidden,'tenant-A','actor-A',$time),'action arguments must not persist');
    $invalidId=new WorkflowDefinition('tenant-A','invalid workflow id','inventory.low_stock',[],[['type'=>'inventory.restock']]);
    denied(fn()=>$repo->save($invalidId,'tenant-A','actor-A',$time),'persisted workflow id must be round-trippable');
    check((int)$db->fetchOne('SELECT COUNT(*) FROM condor_workflow_definition WHERE workflow_id = ?', ['private'])===0,'no partial action persistence');
    check($repo->save($a,'tenant-A','actor-A',$time)===1,'version 1');
    check($repo->save($b,'tenant-B','actor-B',$time)===1,'partitioned version');
    check($repo->save($a,'tenant-A','actor-A',$time)===2,'version 2');
    check($repo->latest('tenant-A','stock')['version']===2,'latest');
    check($repo->latest('tenant-B','stock')['version']===1,'tenant B untouched');
    check($repo->latest('tenant-C','stock')===null,'no cross-tenant read');
    denied(fn()=>$repo->save($a,'tenant-B','actor-B',$time),'cross-tenant save');
    denied(fn()=>$repo->setActive('tenant-A','stock',1,false,'actor-A',$time),'stale version');
    $repo->setActive('tenant-A','stock',2,false,'actor-A',$time);
    check(!$repo->latest('tenant-A','stock')['definition']->active,'disable');
    check($repo->latest('tenant-B','stock')['definition']->active,'isolation');
    $engine=new WorkflowEngine(['inventory.restock'=>'inventory.manage']);
    $trace=$engine->evaluate($a,'tenant-A','inventory.low_stock',[],['inventory.manage']);
    denied(fn()=>$repo->recordExecution($trace,'tenant-A','actor-A','completed',$time),'inactive');
    denied(fn()=>$repo->recordExecution($trace,'tenant-B','actor-B','completed',$time),'cross-tenant trace');
    $repo->setActive('tenant-A','stock',2,true,'actor-A',$time);
    $id=$repo->recordExecution($trace,'tenant-A','actor-A','completed',$time);
    check(strlen($id)===32,'audit id');
    $history=$repo->history('tenant-A','stock');
    check(count($history)===1,'audit persisted');
    check($history[0]['actor_user_id']==='actor-A' && (int)$history[0]['definition_version']===2,'actor/version');
    check($history[0]['result']==='completed' && $history[0]['created_at']==='2026-10-24 12:00:00','time/outcome');
    check($repo->history('tenant-B','stock')===[],'no cross-tenant history');
    denied(fn()=>$repo->history('tenant-A','stock',101),'pagination');
    denied(fn()=>$repo->recordExecution($trace,'tenant-A','actor-A','invalid',$time),'outcome');
    denied(fn()=>$repo->recordExecution($trace,'tenant-A','actor-A','skipped',$time),'plan vs actual');
    $db->executeStatement('UPDATE condor_workflow_definition SET conditions_json = ? WHERE tenant_id = ? AND workflow_id = ? AND version = ?',['{invalid','tenant-A','stock',2]);
    denied(fn()=>$repo->latest('tenant-A','stock'),'corrupt storage');
    echo 'WORKFLOW_PERSISTENCE_OK';
}
'''


class WorkflowPersistenciaTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se requiere PHP real")
        for path in (REPO, MIGRATION):
            self.assertTrue(path.exists(), str(path))
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT,
                                  capture_output=True, text=True, check=False, timeout=15)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        run = subprocess.run([php], input=SCENARIO, cwd=ROOT,
                             capture_output=True, text=True, check=False, timeout=30)
        self.assertEqual(0, run.returncode, run.stdout + run.stderr)
        self.assertEqual("WORKFLOW_PERSISTENCE_OK", run.stdout.strip())

    def test_schema_aditivo(self) -> None:
        sql = MIGRATION.read_text(encoding="utf-8")
        for expected in ("condor_workflow_definition", "condor_workflow_execution",
                         "PRIMARY KEY (tenant_id, workflow_id, version)",
                         "FOREIGN KEY (tenant_id, workflow_id, definition_version)",
                         "IrreversibleMigration", "ON DELETE RESTRICT"):
            self.assertIn(expected, sql)
        self.assertNotIn("DROP TABLE", sql)


if __name__ == "__main__":
    unittest.main()
