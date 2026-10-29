#!/usr/bin/env python3
"""#1334: prueba piezas PHP reales, sin promover filas incompletas a CUBIERTO."""
from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
REQUIRED = frozenset((
    'GEST-FLUJO-3-P02', 'GEST-FLUJO-3-P03', 'GEST-FLUJO-3-P04',
    'GEST-FLUJO-4', 'GEST-FLUJO-5', 'GEST-INTEG-01',
    'GEST-INTEG-02', 'GEST-INTEG-03', 'GEST-INTEG-04',
    'GEST-INTEG-05', 'GEST-INTEG-06', 'GEST-LIM-01',
))
# Las demostraciones de comportamiento parcial no equivalen al flujo integral.
NOT_FULLY_IMPLEMENTED = {
    'GEST-FLUJO-3-P02': ('D', 'Riesgos carece de responsable y evidencia evaluada por control.'),
    'GEST-FLUJO-3-P03': ('D', 'La acción registra plazo y seguimiento pero no cierre humano aprobado.'),
    'GEST-FLUJO-3-P04': ('D', 'El CSV minimiza columnas pero no es tablero agregado sensible con RBAC.'),
    'GEST-FLUJO-4': ('C', 'Los ciclos laborales futuros siguen bloqueados por #742 y D-059/D-063.'),
    'GEST-FLUJO-5': ('B', 'Abastecimiento, activos y operaciones requieren ejecución integrada/persistencia.'),
    'GEST-INTEG-01': ('B', 'La reutilización de entidades en los cinco dominios requiere E2E transaccional.'),
    'GEST-INTEG-02': ('D', 'Entitlements y RBAC existen por separado, no el gate conjunto probado.'),
    'GEST-INTEG-03': ('D', 'Documento sintético no implementa firma con adaptador/proveedor.'),
    'GEST-INTEG-04': ('D', 'Falta contrato versionado transversal nómina, PILA, DIAN y facturación.'),
    'GEST-INTEG-05': ('D', 'La cola de mensajes admite datos libres sin mínimo obligatorio/auditoría.'),
    'GEST-INTEG-06': ('D', 'El CSV limita tenant/columnas, no permiso+entidad+auditoría+retención integral.'),
    'GEST-LIM-01': ('B', 'El aislamiento tenant/entidad/sede exige integración y auditoría persistida.'),
}

PROBE = r"""
require 'src/Application/Risk/RiskRegisterControls.php';
require 'src/Application/Quality/QualityNonconformityActions.php';
require 'src/Application/Assets/AssetRegisterLifecycle.php';
require 'src/Application/Reporting/TenantCsvReportService.php';
require 'src/Application/Documents/DocumentApprovalRetention.php';
require 'src/Application/Commercial/EntitlementSnapshot.php';
use App\Application\Risk\RiskRegisterControls as R;
use App\Application\Quality\QualityNonconformityActions as Q;
use App\Application\Assets\AssetRegisterLifecycle as A;
use App\Application\Reporting\TenantCsvReportService as C;
use App\Application\Documents\DocumentApprovalRetention as D;
use App\Application\Commercial\EntitlementSnapshot as E;
$t='fixture:tenant/alpha';
$risk=['tenant_ref'=>$t,'risks'=>[[
 'tenant_ref'=>$t,'risk_ref'=>'fixture:risk/fire','category'=>'operativo',
 'probability'=>4,'impact'=>5,'state'=>'PENDIENTE']],
 'controls'=>[['tenant_ref'=>$t,'control_ref'=>'fixture:risk-control/guard',
 'risk_ref'=>'fixture:risk/fire','effectiveness'=>50,'state'=>'PENDIENTE']]];
$r=R::project($risk,$t);
$badRisk=$risk;$badRisk['controls'][0]['tenant_ref']='fixture:tenant/beta';
$rb=R::project($badRisk,$t);
$quality=['tenant_ref'=>$t,'nonconformities'=>[[
 'tenant_ref'=>$t,'nonconformity_ref'=>'fixture:quality-nc/nc',
 'finding_ref'=>'fixture:quality-finding/finding',
 'cause_ref'=>'fixture:quality-cause/cause','cause_state'=>'PENDIENTE','state'=>'PENDIENTE']],
 'actions'=>[['tenant_ref'=>$t,'action_ref'=>'fixture:quality-action/fix',
 'nonconformity_ref'=>'fixture:quality-nc/nc','owner_ref'=>'fixture:responsible/owner',
 'due_on'=>'2026-10-01','kind'=>'CORRECTIVA','state'=>'PENDIENTE',
 'verification_state'=>'PENDIENTE']]];
$q=Q::project($quality,$t,'2026-10-29');
$badQuality=$quality;$badQuality['actions'][0]['owner_ref']=null;
$qb=Q::project($badQuality,$t,'2026-10-29');
$assets=['tenant_ref'=>'tenant-test-alpha','company_ref'=>'fixture:company/alpha',
'as_of'=>'2026-10-29','assets'=>[[
 'tenant_ref'=>'tenant-test-alpha','company_ref'=>'fixture:company/alpha',
 'asset_ref'=>'fixture:asset/printer','responsible_ref'=>null,'warranty_until'=>null,
 'maintenance_on'=>null,'action'=>'SOLICITAR_BAJA','evidence_ref'=>'fixture:evidence/approval']]];
$a=A::project($assets);
$badAsset=$assets;$badAsset['assets'][0]['evidence_ref']=null;
$ab=A::project($badAsset);
$foreignAsset=$assets;$foreignAsset['assets'][0]['company_ref']='fixture:company/beta';
$af=A::project($foreignAsset);
$records=[
 ['tenant_id'=>'tenant-test-alpha','occurred_at'=>'2026-10-12',
  'order_id'=>'order-1','currency'=>'COP','total_amount_minor'=>9900,
  'payment_status'=>'PENDING','medical_notes'=>'SENSITIVE_FIELD_SHOULD_NOT_APPEAR'],
 ['tenant_id'=>'tenant-test-beta','occurred_at'=>'2026-10-12',
  'order_id'=>'FOREIGN_RECORD','currency'=>'COP','total_amount_minor'=>9900,
  'payment_status'=>'PENDING']];
$csv=(new C())->build('sales','tenant-test-alpha','2026-10-01','2026-10-31',$records);
$csvDenied=false;
try {(new C())->build('sales','','2026-10-01','2026-10-31',[]);}
catch (\DomainException $e) {$csvDenied=true;}
$docs=['tenant_ref'=>'tenant-test-alpha','company_ref'=>'fixture:company/alpha',
 'as_of'=>'2026-10-29','records'=>[[
 'tenant_ref'=>'tenant-test-alpha','company_ref'=>'fixture:company/alpha',
 'ref'=>'fixture:dossier/one','classification'=>'RESERVADA',
 'author_ref'=>'fixture:actor/author','events'=>[
  ['action'=>'REVISAR','actor_ref'=>'fixture:actor/reviewer','role'=>'revisor','at'=>'2026-10-01'],
  ['action'=>'APROBAR','actor_ref'=>'fixture:actor/approver','role'=>'aprobador','at'=>'2026-10-02']]]]];
$d=D::project($docs);
$badDoc=$docs;$badDoc['records'][0]['events'][1]['actor_ref']='fixture:actor/reviewer';
$db=D::project($badDoc);
$e=new E('tenant-test-alpha','plan',1,'vertical',
 ['reporting'=>true,'hr'=>false],[],[],[],new \DateTimeImmutable('2026-10-29'));
$unknown=false;
try {$e->capability('unregistered');} catch (\DomainException $ex) {$unknown=true;}
echo json_encode([
 'risk'=>['reviewable'=>$r['structurally_reviewable'],
  'inherent'=>$r['risks'][0]['inherent_score'],'residual'=>$r['risks'][0]['residual_score'],
  'has_control'=>$r['risks'][0]['controls'][0]['control_ref'],
  'cross_tenant_denied'=>!$rb['structurally_reviewable']],
 'quality'=>['reviewable'=>$q['structurally_reviewable'],
  'overdue'=>$q['actions'][0]['follow_up_overdue'],
  'owner'=>$q['actions'][0]['owner_ref'],
  'verified'=>$q['actions'][0]['verified'],
  'missing_owner_denied'=>!$qb['structurally_reviewable']],
 'asset'=>['valid'=>$a['valid'],'state'=>$a['assets'][0]['state'],
  'can_close'=>$a['assets'][0]['can_close'],
  'missing_evidence_denied'=>!$ab['valid'],'other_company_hidden'=>count($af['assets'])===0],
 'csv'=>['body'=>$csv['body'],'tenant_required'=>$csvDenied],
 'doc'=>['valid'=>$d['valid'],'state'=>$d['records'][0]['state'],
  'automatic_approval'=>$d['records'][0]['automatic_approval'],
  'self_approval_denied'=>!$db['valid']],
 'entitlement'=>['reporting'=>$e->capability('reporting'),
  'hr'=>$e->capability('hr'),'unknown_denied'=>$unknown],
],JSON_THROW_ON_ERROR);
"""

@lru_cache(maxsize=1)
def real_php() -> dict:
    result = subprocess.run(['php', '-r', PROBE], cwd=ROOT,
                            capture_output=True, text=True, check=False, timeout=50)
    if result.returncode or result.stderr.strip():
        raise AssertionError("PHP real no acreditado: " + (result.stderr + result.stdout)[-800:])
    try:
        value = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("PHP no devolvió JSON válido") from exc
    if not isinstance(value, dict):
        raise AssertionError("PHP no devolvió objeto válido")
    return value


class CoberturaCondor15Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: ejecutar PHP real sin autocertificar obligaciones incompletas."""
        p = real_php()
        self.assertEqual(REQUIRED, set(NOT_FULLY_IMPLEMENTED))
        self.assertTrue(all(k in ('B', 'C', 'D') and why
                            for k, why in NOT_FULLY_IMPLEMENTED.values()))
        with self.subTest(row='GEST-FLUJO-3-P02'):
            self.assertTrue(p['risk']['reviewable'])
            self.assertEqual(20, p['risk']['inherent'])
            self.assertEqual(10, p['risk']['residual'])
            self.assertEqual('fixture:risk-control/guard', p['risk']['has_control'])
        with self.subTest(row='GEST-FLUJO-3-P03'):
            self.assertTrue(p['quality']['reviewable'])
            self.assertEqual('fixture:responsible/owner', p['quality']['owner'])
            self.assertTrue(p['quality']['overdue'])
            self.assertFalse(p['quality']['verified'])
        with self.subTest(row='GEST-FLUJO-5'):
            self.assertTrue(p['asset']['valid'])
            self.assertEqual('BAJA_REQUIERE_REVISION', p['asset']['state'])
            self.assertFalse(p['asset']['can_close'])
        with self.subTest(row='GEST-INTEG-02'):
            self.assertTrue(p['entitlement']['reporting'])
            self.assertFalse(p['entitlement']['hr'])
        with self.subTest(row='GEST-INTEG-03'):
            self.assertTrue(p['doc']['valid'])
            self.assertEqual('APROBADO_EXPLICITO', p['doc']['state'])
            self.assertFalse(p['doc']['automatic_approval'])
        with self.subTest(row='GEST-INTEG-06'):
            self.assertIn('order-1', p['csv']['body'])
            self.assertNotIn('FOREIGN_RECORD', p['csv']['body'])

    def test_falla_cerrado(self) -> None:
        """AC-02: datos inválidos, alcance ajeno, ausencia de dueño y autoaprobación."""
        p = real_php()
        self.assertTrue(p['risk']['cross_tenant_denied'])
        self.assertTrue(p['quality']['missing_owner_denied'])
        self.assertTrue(p['asset']['missing_evidence_denied'])
        self.assertTrue(p['asset']['other_company_hidden'])
        self.assertTrue(p['csv']['tenant_required'])
        self.assertTrue(p['doc']['self_approval_denied'])
        self.assertTrue(p['entitlement']['unknown_denied'])
        self.assertNotIn('SENSITIVE_FIELD_SHOULD_NOT_APPEAR', p['csv']['body'])

    def test_sin_datos_reales(self) -> None:
        """AC-03: datos ficticios; sin HTTP, secretos, PII ni credenciales."""
        self.assertEqual(12, len(REQUIRED))
        source = Path(__file__).read_text(encoding='utf-8')
        self.assertNotIn('curl_' + 'exec(', source)
        self.assertNotIn('https:' + '//', source)
        self.assertNotRegex(source, r'\b(?:github_pat_[A-Za-z0-9]{16,}|ghp_[A-Za-z0-9]{16,})')
        for path in ('src/Application/Risk/RiskRegisterControls.php',
                     'src/Application/Quality/QualityNonconformityActions.php',
                     'src/Application/Assets/AssetRegisterLifecycle.php',
                     'src/Application/Reporting/TenantCsvReportService.php',
                     'src/Application/Documents/DocumentApprovalRetention.php',
                     'src/Application/Commercial/EntitlementSnapshot.php'):
            self.assertTrue((ROOT / path).is_file(), path)
        self.assertNotIn('SENSITIVE_FIELD_SHOULD_NOT_APPEAR', real_php()['csv']['body'])

    def test_validar_exact_head(self) -> None:
        """AC-04: pinning; CI remoto sigue siendo autoridad terminal."""
        c = (ROOT / '.github/workflows/ci.yml').read_text(encoding='utf-8')
        self.assertIn('name: Validar', c)
        self.assertIn('github.event.pull_request.head.sha', c)
        self.assertIn('CI Factory v1', c)


if __name__ == '__main__':
    unittest.main()
