#!/usr/bin/env python3
"""#1341: real offline PHP behavior for GEST-M11-S01..GEST-M14-S02.

Partial M12/M14 controls are not evidence of a complete GRC, workflow,
complaints or information-security module. No production/data permissions.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = tuple(
    [f"GEST-M11-S{i:02d}" for i in range(1, 8)]
    + ["GEST-M12-S04", "GEST-M13-S03", "GEST-M14",
       "GEST-M14-S01", "GEST-M14-S02"]
)
# A = executable but *partial* offline slice; never claim an entire row CUBIERTO.
PARTIAL = {
    "GEST-M12-S04": "Synthetic technological risk queue; reputational GRC unavailable",
    "GEST-M14-S01": "Real PHP permission catalog and staff grant checks, not full RBAC review",
    "GEST-M14-S02": "Real tenant-bound authorization, not end-to-end access governance",
}
# D = the described capability does not exist as an independently testable module.
UNIMPLEMENTED = {
    **{f"GEST-M11-S{i:02d}": (
        "D", "Generic operations workflow/process/task/service-order engine is absent; "
        "production and commerce orders are different bounded contexts"
    ) for i in range(1, 8)},
    "GEST-M13-S03": (
        "D", "QualityApiService has NO_CONFORMIDAD and AUDITORIA_INTERNA, "
        "but no internal-complaints record or reporting workflow"
    ),
    "GEST-M14": (
        "D", "End-to-end information-security management (reviews, backups, "
        "continuity, incident handling) is not implemented as one module"
    ),
}

# Stub only dependency boundaries (ORM and identity records); execute the real
# PHP RiskApiService, QualityApiService, PermissionCatalog and
# PlatformStaffAuthorization classes checked into Condor, not fake responses.
PHP_PROBE = r"""
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getRepository(string $class): object;
    }
}
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $identity) {}
        public function id(): string { return $this->identity; }
    }
}
namespace App\Domain\Identity\Entity {
    use App\Domain\Organization\Entity\Tenant;
    final class User {
        public const ROLE_PLATFORM_OWNER = 'ROLE_PLATFORM_OWNER';
        public const ROLE_PLATFORM_STAFF = 'ROLE_PLATFORM_STAFF';
        public function __construct(private array $roles, private bool $active = true) {}
        public function hasRole(string $role): bool { return in_array($role, $this->roles, true); }
        public function isActive(): bool { return $this->active; }
    }
    final class PlatformStaffGrant {
        public function __construct(
            private User $staff, private Tenant $tenant,
            private string $module, private array $actions
        ) {}
        public function staff(): User { return $this->staff; }
        public function moduleKey(): string { return $this->module; }
        public function covers(Tenant $tenant): bool { return $tenant->id() === $this->tenant->id(); }
        public function allows(string $action): bool { return in_array($action, $this->actions, true); }
        public function tenant(): ?Tenant { return $this->tenant; }
        public function actions(): array { return $this->actions; }
    }
}
namespace {
    require 'src/Application/Risk/RiskApiService.php';
    require 'src/Application/Quality/QualityApiService.php';
    require 'src/Domain/Identity/PermissionCatalog.php';
    require 'src/Application/Identity/PlatformStaffAuthorization.php';

    use App\Application\Risk\RiskApiService as Risk;
    use App\Application\Quality\QualityApiService as Quality;
    use App\Domain\Identity\PermissionCatalog as Catalog;
    use App\Application\Identity\PlatformStaffAuthorization as Auth;
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Identity\Entity\User;
    use App\Domain\Identity\Entity\PlatformStaffGrant;
    use Doctrine\ORM\EntityManagerInterface;

    final class FixtureRepository {
        public function __construct(private array $grants) {}
        public function findBy(array $criteria): array {
            return array_values(array_filter(
                $this->grants,
                fn ($grant) => $grant->staff() === ($criteria['staff'] ?? null)
            ));
        }
    }
    final class FixtureManager implements EntityManagerInterface {
        public function __construct(private array $grants) {}
        public function getRepository(string $class): object {
            return new FixtureRepository($this->grants);
        }
    }
    function rejected(callable $operation): bool {
        try { $operation(); return false; }
        catch (\DomainException $error) { return true; }
    }

    $a = hash('sha256', 'fixture:tenant/a|branch/a');
    $b = hash('sha256', 'fixture:tenant/b|branch/b');
    $riskInput = ['risk_ref'=>'fixture:risk/tech', 'category'=>'tecnologico',
                  'probability'=>2, 'impact'=>4];
    $risk = Risk::createRecord([], $a, $riskInput);
    $riskReviewed = Risk::changeState(
        $risk['store'], $a, 'fixture:risk/tech', 'EN_REVISION'
    );
    $badCategory = $riskInput;
    $badCategory['category'] = 'reputacional';
    $badPerson = $riskInput;
    $badPerson['risk_ref'] = 'person@example.test';

    $qualityInput = [
        'record_ref'=>'fixture:quality-record/sample',
        'source_ref'=>'fixture:quality-source/sample', 'kind'=>'NO_CONFORMIDAD'
    ];
    $quality = Quality::createRecord([], $a, $qualityInput);
    $complaint = $qualityInput;
    $complaint['kind'] = 'QUEJA_INTERNA';

    $tenantA = new Tenant('fixture:tenant/a');
    $tenantB = new Tenant('fixture:tenant/b');
    $staff = new User([User::ROLE_PLATFORM_STAFF]);
    $inactive = new User([User::ROLE_PLATFORM_STAFF], false);
    $owner = new User([User::ROLE_PLATFORM_OWNER]);
    $visitor = new User([]);
    $grant = new PlatformStaffGrant($staff, $tenantA, 'settings', ['view']);
    $auth = new Auth(new FixtureManager([$grant]));

    echo json_encode([
        'risk' => [
            'initial'=>$risk['record'], 'reviewed'=>$riskReviewed['record'],
            'same_scope'=>Risk::viewRecord($riskReviewed['store'], $a, 'fixture:risk/tech'),
            'foreign_scope'=>Risk::viewRecord($riskReviewed['store'], $b, 'fixture:risk/tech'),
            'foreign_list'=>Risk::listRecords($riskReviewed['store'], $b),
            'reputation_denied'=>rejected(fn()=>Risk::createRecord([], $a, $badCategory)),
            'person_denied'=>rejected(fn()=>Risk::createRecord([], $a, $badPerson)),
            'approval_denied'=>rejected(fn()=>Risk::changeState(
                $risk['store'], $a, 'fixture:risk/tech', 'APROBADO')),
            'invalid_scope_denied'=>rejected(fn()=>Risk::listRecords([], 'forged')),
        ],
        'quality' => [
            'initial'=>$quality['record'],
            'foreign'=>Quality::listRecords($quality['store'], $b),
            'complaints_unimplemented'=>rejected(fn()=>Quality::createRecord(
                [], $a, $complaint)),
            'closure_denied'=>rejected(fn()=>Quality::changeState(
                $quality['store'], $a, 'fixture:quality-record/sample', 'CERRADO')),
        ],
        'access' => [
            'normalized'=>Catalog::normalize(['settings.view','roles.view','settings.view']),
            'allowed'=> $auth->can($staff, $tenantA, 'settings.view'),
            'no_write'=> $auth->can($staff, $tenantA, 'settings.update'),
            'no_foreign'=> $auth->can($staff, $tenantB, 'settings.view'),
            'no_inactive'=> $auth->can($inactive, $tenantA, 'settings.view'),
            'no_visitor'=> $auth->can($visitor, $tenantA, 'settings.view'),
            'owner'=> $auth->can($owner, $tenantA, 'settings.delete'),
            'tenant_visible'=> $auth->canAccessTenant($staff, $tenantA),
            'foreign_tenant_hidden'=> $auth->canAccessTenant($staff, $tenantB),
            'grants'=> $auth->permissions($staff, $tenantA),
            'invalid_permission_denied'=>rejected(
                fn()=>Catalog::normalize(['settings.execute'])),
        ],
    ], JSON_THROW_ON_ERROR);
}
"""


def _matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(text, str) or not text:
        raise AssertionError("UNKNOWN: matrix missing")
    result: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        found = re.match(
            r"^\|\s*\x60(GEST-M(?:11-S0[1-7]|12-S04|13-S03|14(?:-S0[12])?))\x60\s*·",
            line,
        )
        if found is None:
            continue
        key = found.group(1)
        fields = tuple(value.strip() for value in line.strip().strip("|").split("|"))
        if (key in result or len(fields) != 5
                or fields[2] != "\x60NO_VERIFICABLE\x60"
                or fields[4] != "#802"
                or "gestion-empresarial-integral.md" not in fields[0]
                or not all(fields)):
            raise AssertionError("UNKNOWN: matrix row ambiguous: " + key)
        result[key] = fields
    if set(result) != set(IDS):
        raise AssertionError("UNKNOWN: row missing or unexpected")
    return result


@lru_cache(maxsize=1)
def _php() -> dict[str, object]:
    executable = shutil.which("php")
    if executable is None:
        raise AssertionError("UNKNOWN: real PHP CLI required")
    result = subprocess.run(
        [executable, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=25, check=False,
        env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")},
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: PHP probe failed: " + result.stderr[-600:])
    try:
        parsed = json.loads(result.stdout)
    except ValueError as error:
        raise AssertionError("UNKNOWN: invalid PHP output") from error
    if not isinstance(parsed, dict) or set(parsed) != {"risk", "quality", "access"}:
        raise AssertionError("UNKNOWN: incomplete PHP output")
    return parsed


class CoberturaCondor22Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = _matrix_rows(MATRIX.read_text(encoding="utf-8"))
        cls.proof = _php()

    def test_contrato_principal(self) -> None:
        """AC-01: three partial PHP behaviors, nine explicit missing capabilities."""
        self.assertEqual(set(PARTIAL) | set(UNIMPLEMENTED), set(IDS))
        self.assertFalse(set(PARTIAL) & set(UNIMPLEMENTED))
        for code, cells in self.rows.items():
            with self.subTest(code=code):
                self.assertIn(code, cells[0])
                if code in UNIMPLEMENTED:
                    category, reason = UNIMPLEMENTED[code]
                    self.assertEqual("D", category)
                    self.assertGreater(len(reason), 35)

        risk = self.proof["risk"]
        with self.subTest(code="GEST-M12-S04"):
            self.assertEqual("tecnologico", risk["initial"]["category"])
            self.assertEqual(8, risk["initial"]["probability"] * risk["initial"]["impact"])
            self.assertEqual("PENDIENTE", risk["initial"]["state"])
            self.assertEqual("EN_REVISION", risk["reviewed"]["state"])
            self.assertEqual(risk["reviewed"], risk["same_scope"])

        access = self.proof["access"]
        with self.subTest(code="GEST-M14-S01"):
            self.assertEqual(["roles.view", "settings.view"], access["normalized"])
            self.assertEqual(["settings.view"], access["grants"])
            self.assertIs(access["allowed"], True)
        with self.subTest(code="GEST-M14-S02"):
            self.assertIs(access["tenant_visible"], True)
            self.assertIs(access["owner"], True)
        self.assertEqual("NO_VERIFICABLE", self.rows["GEST-M14"][2].strip(chr(96)))

    def test_falla_cerrado(self) -> None:
        """AC-02: forged, missing, foreign or ungranted PHP inputs deny."""
        risk = self.proof["risk"]
        self.assertIsNone(risk["foreign_scope"])
        self.assertEqual([], risk["foreign_list"])
        for key in ("reputation_denied", "person_denied", "approval_denied",
                    "invalid_scope_denied"):
            with self.subTest(risk=key):
                self.assertIs(risk[key], True)

        quality = self.proof["quality"]
        self.assertEqual([], quality["foreign"])
        self.assertIs(quality["complaints_unimplemented"], True)
        self.assertIs(quality["closure_denied"], True)
        access = self.proof["access"]
        for key in ("no_write", "no_foreign", "no_inactive", "no_visitor",
                    "foreign_tenant_hidden"):
            with self.subTest(permission=key):
                self.assertIs(access[key], False)
        self.assertIs(access["invalid_permission_denied"], True)
        missing = "| " + chr(96) + "GEST-M14-S01" + chr(96)
        broken = MATRIX.read_text(encoding="utf-8")
        self.assertRaises(AssertionError, _matrix_rows, broken.replace(missing, "| GONE", 1))
        self.assertRaises(AssertionError, _matrix_rows, None)

    def test_sin_datos_reales(self) -> None:
        """AC-03: sandbox-only classes, PHP -n, no network/PII/credential I/O."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertTrue(data["d063_attestation"]["no_real_customer_data"])
        self.assertTrue(data["d063_attestation"]["nothing_live"])
        self.assertNotIn("file_get_contents(", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("https://", PHP_PROBE)
        self.assertNotIn("Authorization:", PHP_PROBE)
        self.assertEqual(set(PARTIAL) & set(UNIMPLEMENTED), set())

    def test_ci_gate_contract(self) -> None:
        """AC-04: CI wiring; terminal exact-HEAD Validar is checked on GitHub."""
        ci = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", ci)
        self.assertIn("Pruebas de contrato e integración", ci)
        self.assertIn("python3 -m unittest discover", ci)
        self.assertEqual(set(_matrix_rows(MATRIX.read_text(encoding="utf-8"))), set(IDS))


if __name__ == "__main__":
    unittest.main()
