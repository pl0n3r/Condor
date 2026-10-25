#!/usr/bin/env python3
"""Condor #857 AC-01: real PHP controller + real workflow planner, synthetic fake repositories."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/WorkflowController.php"
CORE = ROOT / "src/Domain/Workflow"

SCENARIO = r'''<?php
declare(strict_types=1);
namespace Symfony\Component\HttpKernel\Exception {
    class AccessDeniedHttpException extends \RuntimeException {}
    class NotFoundHttpException extends \RuntimeException {}
    class BadRequestHttpException extends \RuntimeException {}
    class UnprocessableEntityHttpException extends \RuntimeException { public function __construct(string $message='', ?\Throwable $previous=null){parent::__construct($message,0,$previous);} }
    class ConflictHttpException extends \RuntimeException {}
}
namespace Symfony\Component\Security\Core\Exception {
    class AccessDeniedException extends \RuntimeException {}
}
namespace Symfony\Component\Routing\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)] class Route {
        public function __construct(public string $path,public string $name='',public array $methods=[]){}
    }
}
namespace Symfony\Component\HttpFoundation {
    class ParameterBag {
        public function __construct(private array $v=[]){}
        public function get(string $k,mixed $default=null): mixed{return $this->v[$k]??$default;}
    }
    class Request {
        public ParameterBag $headers;
        public ParameterBag $query;
        public function __construct(private string $content='',array $headers=[],array $query=[]){
            $this->headers=new ParameterBag($headers);$this->query=new ParameterBag($query);
        }
        public function getContent(): string{return $this->content;}
    }
    class Response {
        public const HTTP_OK=200; public const HTTP_CREATED=201; public const HTTP_NO_CONTENT=204;
        public function __construct(public string $content='',public int $status=200){}
    }
    class JsonResponse extends Response {
        public function __construct(public array $data=[],int $status=200){parent::__construct('', $status);}
    }
}
namespace App\Domain\Identity\Entity { class User {
    public function __construct(private string $id){} public function id():string{return $this->id;}
}}
namespace App\Domain\Organization\Entity {
    class Tenant {public function __construct(private string $id){}public function id():string{return $this->id;}}
    class Branch {
        public function __construct(private string $id, private Tenant $tenant){}
        public function id():string{return $this->id;}public function tenant():Tenant{return $this->tenant;}
    }
}
namespace App\Domain\Audit\Entity {
    class AuditEvent {
        public function __construct(
            public \App\Domain\Organization\Entity\Tenant $tenant,
            public ?string $actor,
            public string $action,
            public string $entityType,
            public string $entityId,
            public array $context = [],
        ) {}
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getRepository(string $s): object;
        public function persist(object $entity): void;
        public function flush(): void;
    }
}
namespace App\Application\Identity {
    use App\Domain\Identity\Entity\User;
    use App\Domain\Organization\Entity\{Tenant,Branch};
    class CurrentTenantForUser {
        public function __construct(public Tenant $tenant){}
        public function resolve(User $user):Tenant {return $this->tenant;}
    }
    class BranchAuthorization {
        public function __construct(private bool $admin=true,private array $permissions=['settings.update','inventory.update']){}
        public function require(User $u,Tenant $t,Branch $b,string $permission):object {
            if(!$this->admin || $permission!=='settings.update' || $t->id()!==$b->tenant()->id()) {
                throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('permission');
            }
            return new \stdClass();
        }
        public function permissions(User $u,Tenant $t,Branch $b):array {return $this->permissions;}
    }
}
namespace Doctrine\DBAL {
    class Connection {
        public int $locks=0;
        public int $transactions=0;
        public function __construct(private \App\Infrastructure\Persistence\WorkflowRepository $repo){}
        public function transactional(callable $operation):mixed {
            $this->transactions++;
            return $operation($this);
        }
        public function fetchOne(string $sql,array $params):mixed {
            if($sql!=='SELECT id FROM condor_tenant WHERE id = ? FOR UPDATE')throw new \RuntimeException('missing tenant row lock');
            $this->locks++;
            return $params[0];
        }
        public function fetchFirstColumn(string $sql,array $params):array {
            if(!str_contains($sql,'WHERE tenant_id = ?'))throw new \RuntimeException('unscoped SQL');
            return $this->repo->ids($params[0]);
        }
    }
}
namespace App\Infrastructure\Persistence {
    use App\Domain\Workflow\WorkflowDefinition;
    class WorkflowRepository {
        public array $versions=[]; public array $history=[]; public int $writes=0;
        public function save(WorkflowDefinition $d,string $tenant,string $actor,\DateTimeImmutable $now):int {
            if($d->tenantId!==$tenant)throw new \DomainException('cross-tenant');
            foreach($d->actions as $a)if(array_keys($a)!==['type'])throw new \DomainException('arguments');
            $v=count($this->versions[$tenant][$d->workflowId]??[])+1;
            $this->versions[$tenant][$d->workflowId][$v]=$d;$this->writes++;return $v;
        }
        public function latest(string $tenant,string $id):?array {
            if(!isset($this->versions[$tenant][$id]))return null;
            $v=count($this->versions[$tenant][$id]);return ['definition'=>$this->versions[$tenant][$id][$v],'version'=>$v];
        }
        public function setActive(string $tenant,string $id,int $version,bool $active,string $actor,\DateTimeImmutable $now):void{
            $old=$this->latest($tenant,$id);
            if($old===null || $old['version']!==$version)throw new \DomainException('stale');
            $d=$old['definition'];$this->versions[$tenant][$id][$version]=new WorkflowDefinition(
                $tenant,$id,$d->trigger,$d->conditions,$d->actions,$active
            );$this->writes++;
        }
        public function history(string $tenant,string $id,int $limit=50):array {return array_slice($this->history[$tenant][$id]??[],0,$limit);}
        public function ids(string $tenant):array {$i=array_keys($this->versions[$tenant]??[]);sort($i);return $i;}
    }
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
    use Symfony\Component\HttpFoundation\JsonResponse;
    use App\Domain\Identity\Entity\User;
    class AbstractController {
        public static ?User $actor=null;
        public static bool $csrf=true;
        public function getUser():?User{return self::$actor;}
        public function isCsrfTokenValid(string $id,string $token):bool{return self::$csrf && $id==='branch_access' && $token==='csrf-ok';}
        public function json(array $data,int $status=200):JsonResponse{return new JsonResponse($data,$status);}
    }
}
namespace Test {
    use App\Application\Identity\{BranchAuthorization,CurrentTenantForUser};
    use App\Domain\Organization\Entity\{Tenant,Branch};
    use App\Domain\Identity\Entity\User;
    use App\Http\Controller\WorkflowController;
    use App\Infrastructure\Persistence\WorkflowRepository;
    use Doctrine\DBAL\Connection;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpKernel\Exception\{UnprocessableEntityHttpException,NotFoundHttpException,AccessDeniedHttpException,ConflictHttpException};
    class Em implements EntityManagerInterface {
        public array $events=[]; public int $flushes=0;
        public function __construct(private array $branches){}
        public function persist(object $event):void {
            if(!$event instanceof \App\Domain\Audit\Entity\AuditEvent)throw new \RuntimeException('audit event required');
            $this->events[]=$event;
        }
        public function flush():void {$this->flushes++;}
        public function getRepository(string $s):object{return new class($this->branches) {
            public function __construct(private array $branches){}
            public function findOneBy(array $where):?Branch{
                $branch=$this->branches[$where['id']]??null;
                return $branch!==null && $branch->tenant()->id()===$where['tenant']->id()?$branch:null;
            }
        };}
    }
    require_once getcwd().'/src/Domain/Workflow/WorkflowDefinition.php';
    require_once getcwd().'/src/Domain/Workflow/ExecutionTrace.php';
    require_once getcwd().'/src/Domain/Workflow/WorkflowEngine.php';
    require_once getcwd().'/src/Http/Controller/BranchApiSupport.php';
    require_once getcwd().'/src/Http/Controller/WorkflowController.php';
    function check(bool $truth,string $reason):void{if(!$truth)throw new \RuntimeException($reason);}
    function deny(callable $f,string $exception,string $reason):void{
        try{$f();}catch(\Throwable $e){if($e instanceof $exception)return;throw new \RuntimeException($reason.': unexpected '.get_class($e).': '.$e->getMessage());}
        throw new \RuntimeException($reason.': unexpectedly allowed');
    }
    function req(array $body=[],bool $csrf=true,array $query=[]):Request{return new Request(json_encode($body,JSON_THROW_ON_ERROR),['X-CSRF-Token'=>$csrf?'csrf-ok':'invalid'],$query);}
    $a=new Tenant('tenant-A');$b=new Tenant('tenant-B');$repo=new WorkflowRepository();
    $auth=new BranchAuthorization();$ctx=new CurrentTenantForUser($a);
    $em=new Em(['branchA'=>new Branch('branchA',$a),'branchB'=>new Branch('branchB',$b)]);
    $db=new Connection($repo);$ctrl=new WorkflowController($ctx,$auth,$em,$repo,$db);
    AbstractController::$actor=new User('actor-A');
    $def=['workflow_id'=>'stock','trigger'=>'inventory.low_stock','conditions'=>[
        ['field'=>'inventory.quantity','operator'=>'eq','value'=>2],
    ],'actions'=>[['type'=>'inventory.restock']],'active'=>true];
    deny(fn()=>$ctrl->create('branchA',req($def,false)),AccessDeniedHttpException::class,'CSRF');
    deny(fn()=>$ctrl->create('branchB',req($def)),NotFoundHttpException::class,'cross-tenant branch');
    $bad=$def;$bad['actions']=[['type'=>'unregistered.execute']];
    deny(fn()=>$ctrl->create('branchA',req($bad)),UnprocessableEntityHttpException::class,'unknown action');
    $bad=$def;$bad['actions']=[['type'=>'inventory.restock','arguments'=>['key'=>'private']]];
    deny(fn()=>$ctrl->create('branchA',req($bad)),UnprocessableEntityHttpException::class,'action arguments forbidden');
    $bad=$def;$bad['conditions']=[['field'=>'inventory.quantity','operator'=>'eval','value'=>2]];
    deny(fn()=>$ctrl->create('branchA',req($bad)),UnprocessableEntityHttpException::class,'untrusted expression');
    $created=$ctrl->create('branchA',req($def));check($created->status===201 && $created->data['workflow']['version']===1,'create');
    check($db->locks>=1 && $db->transactions===$db->locks,'all attempted writes acquire the tenant lock');
    check(count($em->events)===1 && $em->flushes===1 && $em->events[0]->action==='workflow.created','create audit persisted');
    check($em->events[0]->entityId===hash('sha256','tenant-A:stock'),'auditable stable workflow identity');
    $invalid=$def;$invalid['workflow_id']='not valid with spaces';
    deny(fn()=>$ctrl->create('branchA',req($invalid)),UnprocessableEntityHttpException::class,'invalid id sanitized');
    check(count($ctrl->index('branchA')->data['workflows'])===1,'scoped listing');
    check($ctrl->actions('branchA')->data['actions']===['inventory.restock','notifications.queue'],'allowlisted actions');
    deny(fn()=>$ctrl->create('branchA',req($def)),ConflictHttpException::class,'duplicate');
    check($ctrl->show('branchA','stock')->data['workflow']['active'],'show');
    deny(fn()=>$ctrl->show('branchA','private'),NotFoundHttpException::class,'not found');
    $before=$repo->writes;$run=$ctrl->dryRun('branchA','stock',req(['event_type'=>'inventory.low_stock','event'=>['inventory'=>['quantity'=>2,'private'=>'NO_LOG_857']]]));
    check($run->data['trace']['status']==='planned' && $run->data['persisted']===false,'dry run plan');
    check(!str_contains(json_encode($run->data,JSON_THROW_ON_ERROR),'NO_LOG_857'),'no secret leakage');
    check($repo->writes===$before && $repo->history('tenant-A','stock')===[],'dry run not persisted');
    $bad=$def;unset($bad['workflow_id']);$bad['expected_version']=2;
    deny(fn()=>$ctrl->update('branchA','stock',req($bad)),ConflictHttpException::class,'stale update');
    $bad['expected_version']=1;$bad['trigger']='inventory.threshold';
    $updated=$ctrl->update('branchA','stock',req($bad));check($updated->data['workflow']['version']===2,'versioned update');
    check(count($em->events)===2 && $em->flushes===2 && $em->events[1]->action==='workflow.updated','update audit persisted');
    check($db->locks>=3,'version rechecked under row lock');
    $hist=$ctrl->history('branchA','stock',req([],true,['limit'=>'50']));check($hist->data['executions']===[],'audit history');
    deny(fn()=>$ctrl->history('branchA','stock',req([],true,['limit'=>'101'])),UnprocessableEntityHttpException::class,'history cap');
    deny(fn()=>$ctrl->delete('branchA','stock',req(['expected_version'=>1])),ConflictHttpException::class,'delete stale');
    check($ctrl->delete('branchA','stock',req(['expected_version'=>2]))->status===204,'soft delete');
    check(count($em->events)===3 && $em->flushes===3 && $em->events[2]->action==='workflow.deactivated','deactivation audit persisted');
    check($em->events[2]->context['version']===2,'deactivation records version');
    $huge=req(['event_type'=>'inventory.threshold','event'=>['filler'=>str_repeat('a',9000)]]);
    deny(fn()=>$ctrl->dryRun('branchA','stock',$huge),UnprocessableEntityHttpException::class,'size limit before decode');
    deny(fn()=>$ctrl->delete('branchA','stock',req(['expected_version'=>2,'filler'=>str_repeat('a',9000)])),UnprocessableEntityHttpException::class,'delete size limit');
    $skipped=$ctrl->dryRun('branchA','stock',req(['event_type'=>'inventory.threshold','event'=>['inventory'=>['quantity'=>2]]]));
    check($skipped->data['trace']['reason']==='inactive','disabled dry-run');
    check($repo->writes===$before+2,'update and deactivate audit writes');
    $ctx->tenant=$b;check($ctrl->index('branchB')->data['workflows']===[],'tenant isolation list');
    deny(fn()=>$ctrl->show('branchB','stock'),NotFoundHttpException::class,'tenant isolation read');
    deny(fn()=>$ctrl->dryRun('branchB','stock',req(['event_type'=>'inventory.threshold','event'=>['synthetic'=>true]])),NotFoundHttpException::class,'tenant isolation simulation');
    $ctx->tenant=$a;deny(fn()=>$ctrl->history('branchB','stock',req()),NotFoundHttpException::class,'tenant isolation history');
    $unauthorized=new WorkflowController($ctx,new BranchAuthorization(false),$em,$repo,new Connection($repo));
    deny(fn()=>$unauthorized->index('branchA'),\Symfony\Component\Security\Core\Exception\AccessDeniedException::class,'role permissions');
    $onlySettings=new WorkflowController($ctx,new BranchAuthorization(true,['settings.update']),$em,$repo,new Connection($repo));
    $bad=$def;$bad['workflow_id']='no-stock-permission';
    deny(fn()=>$onlySettings->create('branchA',req($bad)),UnprocessableEntityHttpException::class,'registered action permission');
    check(count($repo->versions['tenant-A'])===1,'no unauthorized writes');
    echo 'WORKFLOW_API_OK';
}
'''


class WorkflowApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP real es obligatorio")
        for path in (CONTROLLER, *[CORE / name for name in ("WorkflowDefinition.php", "WorkflowEngine.php", "ExecutionTrace.php")]):
            self.assertTrue(path.exists(), str(path))
            lint = subprocess.run([php, "-l", str(path)], cwd=ROOT, capture_output=True, text=True, timeout=15)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
        result = subprocess.run([php], input=SCENARIO, cwd=ROOT, capture_output=True, text=True, timeout=30)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertEqual("WORKFLOW_API_OK", result.stdout.strip())


if __name__ == '__main__':
    unittest.main()
