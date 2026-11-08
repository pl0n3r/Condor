#!/usr/bin/env python3
"""Cumplimiento legal #1660: isolation, immutable audit, deny-by-default and D-059."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / "src"
API = PHP / "Http/Controller/ComplianceController.php"
SERVICE = PHP / "Application/Compliance/ComplianceEvidenceRecordService.php"
MIGRATION = ROOT / "migrations/Version20261106135000.php"
TENANT_A = "01ARZ3NDEKTSV4RRFFQ69G5FAV"
TENANT_B = "01ARZ3NDEKTSV4RRFFQ69G5FAW"
COMPANY_A = "01ARZ3NDEKTSV4RRFFQ69G5FAX"
COMPANY_B = "01ARZ3NDEKTSV4RRFFQ69G5FAY"
ACTOR = "01ARZ3NDEKTSV4RRFFQ69G5FAZ"

DB_SETUP = r"""
$db = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_sqlite', 'memory' => true,
]);
$db->executeStatement(
    'CREATE TABLE condor_compliance_real_record (id VARCHAR(26) PRIMARY KEY, '
    .'tenant_id VARCHAR(26), company_id VARCHAR(26), record_ref VARCHAR(64), '
    .'kind VARCHAR(20), state VARCHAR(20), version INT, '
    .'previous_id VARCHAR(26), actor_id VARCHAR(26), occurred_at DATETIME, '
    .'UNIQUE(tenant_id, company_id, record_ref, version))'
);
$records = new \App\Application\Compliance\ComplianceEvidenceRecordService($db);
"""


class ComplianceEvidencePersistenceTests(unittest.TestCase):
    def php_case(self, script: str):
        """Execute the real PHP implementation against a disposable SQLite DB."""
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required")
        vendor = ROOT / "vendor/autoload.php"
        if not vendor.is_file():
            self.fail("Composer dependencies required")
        try:
            output = subprocess.check_output(
                [php, "-r", "require $argv[1]; " + DB_SETUP + script, str(vendor)],
                cwd=ROOT, stderr=subprocess.PIPE, text=True, timeout=35,
            )
        except subprocess.CalledProcessError as exc:
            self.fail(f"PHP/SQLite acceptance failed: {exc.stderr}")
        return json.loads(output)

    def test_tenant_and_company_scope_with_append_only_audit(self):
        result = self.php_case(f"""
$t = '{TENANT_A}'; $other = '{TENANT_B}';
$c = '{COMPANY_A}'; $second = '{COMPANY_B}';
$a = '{ACTOR}'; $input = ['record_ref' => 'riesgo', 'kind' => 'EVIDENCIA'];
$first = $records->add($t, $c, $a, $input);
$records->add($other, $c, $a, $input);
$records->add($t, $second, $a, $input);
$change = $records->transition($t, $c, $a, 'riesgo', 'EN_REVISION', 1);
echo json_encode([
    count($records->all($t, $c)),
    count($records->all($other, $c)),
    count($records->all($t, $second)),
    count($records->all($other, $second)),
    $records->one($t, $c, 'riesgo')['version'],
    $change['previous_id'] === $first['id'],
    $change['actor_id'] === $a,
    (int) $db->fetchOne('SELECT COUNT(*) FROM condor_compliance_real_record '
        .'WHERE tenant_id=? AND company_id=?', [$t, $c]),
]);
""")
        self.assertEqual([1, 1, 1, 0, 2, True, True, 2], result)

    def test_never_accept_clinical_data_duplicate_or_auto_approval(self):
        self.assertEqual(self.php_case(f"""
$t='{TENANT_A}'; $c='{COMPANY_A}'; $a='{ACTOR}';
$input=['record_ref'=>'inspeccion','kind'=>'OBLIGACION'];
$records->add($t,$c,$a,$input);
$attempts=[
    fn()=> $records->add($t,$c,$a,$input),
    fn()=> $records->add($t,$c,$a,[...$input,'record_ref'=>'persona@example.com']),
    fn()=> $records->add($t,$c,$a,[...$input,'kind'=>'DIAGNOSTICO']),
    fn()=> $records->add($t,$c,$a,[...$input,'historia_clinica'=>'x']),
    fn()=> $records->transition($t,$c,$a,'inspeccion','APROBADO',1),
    fn()=> $records->transition($t,$c,$a,'inspeccion','EN_REVISION',12),
    fn()=> $records->transition($t,$c,$a,'ausente','EN_REVISION',1),
];
$rejections=[];
foreach($attempts as $attempt) {{
    try {{ $attempt(); $rejections[]=false; }}
    catch(\\DomainException $e) {{ $rejections[]=true; }}
}}
echo json_encode($rejections);
"""), [True] * 7)

    def test_http_routes_are_owner_only_off_by_default(self):
        source = API.read_text(encoding="utf-8")
        self.assertEqual(4, source.count("name: 'api_compliance_real_"))
        for name in (
            "complianceRealList", "complianceRealView",
            "complianceRealCreate", "complianceRealState",
        ):
            self.assertIn("function " + name, source)
        self.assertEqual(source.count("($off = $this->complianceRealDisabled())"), 2)
        self.assertIn("getenv('LEGAL_COMPLIANCE_REAL_DATA_ENABLED') === 'true'", source)
        self.assertIn("'feature_unavailable'", source)
        self.assertIn("Membership::ROLE_OWNER", source)
        self.assertIn("requireCsrf($request)", source)
        self.assertIn("$legalEntity?->tenant()->id() !== $tenant->id()", source)
        self.assertIn("'settings.view'", source)
        self.assertIn("'settings.create'", source)
        self.assertIn("'settings.update'", source)
        statement = SERVICE.read_text(encoding="utf-8")
        for token in (
            "r.tenant_id = :tenant", "r.company_id = :company",
            "record.tenant_id = :tenant", "record.company_id = :company",
        ):
            self.assertIn(token, statement)

    def test_expand_only_schema_and_privacy_inventory(self):
        ddl = MIGRATION.read_text(encoding="utf-8")
        for token in (
            "CREATE TABLE condor_compliance_real_record",
            "FOREIGN KEY (tenant_id, company_id)",
            "'EN_REVISION'", "IrreversibleMigration",
        ):
            self.assertIn(token, ddl)
        document = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        treatment = [
            item for item in document["treatments"]
            if item["id"] == "legal_compliance_real_records"
        ]
        self.assertEqual(1, len(treatment))
        self.assertEqual("review_required", treatment[0]["basis"])
        self.assertEqual([], treatment[0]["providers"])
        self.assertEqual("construccion", document["phase"])
        self.assertEqual(
            {"nothing_live": True, "no_real_customer_data": True},
            document["d063_attestation"],
        )


if __name__ == "__main__":
    unittest.main()
