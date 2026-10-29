#!/usr/bin/env python3
"""Condor #1325: real, offline PHP security controls for D-040-S01..S12.

A = an existing PHP implementation is directly exercised, but its overall
matrix criterion remains NO_VERIFICABLE until an integral independent audit.
B = the remaining integral claim requires live/browser/DB/log/backup evidence.
No network, credentials, PII, production data, or coverage promotion.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CI = ROOT / ".github/workflows/ci.yml"
TOKEN_CODE = ROOT / "src/Application/Identity/PasswordResetSecurity.php"
PERMISSION_CODE = ROOT / "src/Domain/Identity/PermissionCatalog.php"
RESTORE_CODE = ROOT / "scripts/verify-backup-restore.sh"
UNKNOWN = "UNKNOWN"

ROWS = {
    "D-040-S01": "1. Autenticación segura",
    "D-040-S02": "2. Política de sesión",
    "D-040-S03": "3. Protección CSRF",
    "D-040-S04": "4. Consultas parametrizadas e inyección",
    "D-040-S05": "5. Validación server-side",
    "D-040-S06": "6. Rate limiting y defensa contra abuso",
    "D-040-S07": "7. Secretos y variables de entorno",
    "D-040-S08": "8. Headers de seguridad",
    "D-040-S09": "9. Logging seguro y manejo de errores",
    "D-040-S10": "10. Auditoría administrativa",
    "D-040-S11": "11. Backups y restauración verificable",
    "D-040-S12": "12. Rehearsal de recuperación",
}

# A is a directly executed real-code subset, not a full-row certification.
# B explicitly requires evidence that this isolated offline leaf cannot obtain.
DISPOSITION = {
    "D-040-S01": ("A", "PasswordResetSecurity::issue/hashRawToken; B para login, rehash y anti-enumeración E2E"),
    "D-040-S02": ("B", "cookies del navegador real, rotación, inactividad 2h y duración absoluta 12h"),
    "D-040-S03": ("B", "inventario total de rutas web y solicitudes React/AJAX con CSRF real"),
    "D-040-S04": ("B", "SQL/DQL y allowlists en todos los endpoints contra motor DB real"),
    "D-040-S05": ("A", "PermissionCatalog::normalize/normalizeActions; B para DTO, tenant y mass assignment"),
    "D-040-S06": ("B", "límite distribuido IP+identidad con stores y concurrencia reales"),
    "D-040-S07": ("B", "logs, artefactos, almacenes de secretos y rotación por entorno"),
    "D-040-S08": ("B", "headers CSP/HSTS de todas las respuestas reales públicas y privadas"),
    "D-040-S09": ("B", "captura/redacción auténtica de errores y logs con correlación"),
    "D-040-S10": ("B", "eventos administrativos persistidos, integridad y retención real"),
    "D-040-S11": ("B", "backups reales diarios cifrados, retención y restauración probada"),
    "D-040-S12": ("B", "recibo trimestral real de restore aislado con métricas RTO/RPO"),
}

PHP_TOKEN = r"""
require 'src/Application/Identity/PasswordResetSecurity.php';
$s = new App\Application\Identity\PasswordResetSecurity();
[$raw, $hash, $expiry] = $s->issue();
[$nextRaw, $nextHash] = $s->issue();
$delta = $expiry->getTimestamp() - $s->now()->getTimestamp();
echo json_encode([
    'random_hex_64' => strlen($raw) === 64 && ctype_xdigit($raw),
    'independent_random_tokens' => $raw !== $nextRaw && $hash !== $nextHash,
    'sha256_digest' => strlen($hash) === 64 && hash_equals($hash, hash('sha256', $raw)),
    'round_trip' => hash_equals($hash, $s->hashRawToken($raw) ?? ''),
    'case_normalization' => hash_equals($hash, $s->hashRawToken(strtoupper($raw)) ?? ''),
    'ttl_one_hour' => $delta >= 3595 && $delta <= 3601,
    'reject_missing' => $s->hashRawToken('') === null,
    'reject_short' => $s->hashRawToken(str_repeat('a', 63)) === null,
    'reject_non_hex' => $s->hashRawToken(str_repeat('g', 64)) === null,
    'reject_embedded' => $s->hashRawToken(str_repeat('a', 64) . 'x') === null,
], JSON_THROW_ON_ERROR);
"""

PHP_PERMISSIONS = r"""
require 'src/Domain/Identity/PermissionCatalog.php';
$c = App\Domain\Identity\PermissionCatalog::class;
$invalid = [];
foreach ([
    fn () => $c::normalize(['catalog.admin']),
    fn () => $c::normalize(['catalog.view', null]),
    fn () => $c::normalizeActions(['view', 'export']),
    fn () => $c::normalizeActions([false]),
    fn () => $c::normalizeModule('secret_module'),
] as $try) {
    try {
        $try();
        $invalid[] = false;
    } catch (DomainException $e) {
        $invalid[] = true;
    }
}
echo json_encode([
    'allowlisted_permissions' => $c::normalize([
        'inventory.view', 'catalog.create', 'inventory.view'
    ]) === ['catalog.create', 'inventory.view'],
    'allowlisted_actions' => $c::normalizeActions([
        'view', 'create', 'view'
    ]) === ['create', 'view'],
    'allowlisted_module' => $c::normalizeModule('inventory') === 'inventory',
    'reject_unrecognized' => count($invalid) === 5 && !in_array(false, $invalid, true),
], JSON_THROW_ON_ERROR);
"""


def run_real_php(script: str) -> dict[str, bool]:
    """Execute the repository's actual PHP classes, never a Python reimplementation."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("PHP CLI obligatorio para probar código real")
    run = subprocess.run(
        [php, "-d", "display_errors=stderr", "-r", script],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
        env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LC_ALL": "C"},
    )
    if run.returncode != 0:
        raise AssertionError("PHP real falló: " + (run.stdout + run.stderr)[-1800:])
    payload = json.loads(run.stdout)
    if (type(payload) is not dict or not payload
            or any(type(value) is not bool for value in payload.values())):
        raise AssertionError("UNKNOWN: resultado de PHP no verificable")
    return payload


def full_system_status(_gap: object, _evidence: object = None) -> str:
    """Neither synthetic inputs nor local-code success prove full-system coverage."""
    return UNKNOWN


class CoberturaCondor06Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 12 real matrix entries; directly execute existing PHP for A."""
        self.assertEqual(set(ROWS), set(DISPOSITION))
        for key, (kind, reason) in DISPOSITION.items():
            with self.subTest(row=key):
                self.assertIn(kind, ("A", "B", "C", "D"))
                self.assertTrue(reason)
                self.assertEqual(full_system_status(key, reason), UNKNOWN)
        for path in (TOKEN_CODE, PERMISSION_CODE):
            self.assertTrue(path.is_file(), str(path))
        for name, script in (("D-040-S01", PHP_TOKEN),
                             ("D-040-S05", PHP_PERMISSIONS)):
            with self.subTest(php_real=name):
                results = run_real_php(script)
                self.assertTrue(all(results.values()), results)

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid row, forged coverage or missing permissions never pass."""
        token = run_real_php(PHP_TOKEN)
        catalog = run_real_php(PHP_PERMISSIONS)
        self.assertTrue(all(token[k] for k in (
            "reject_missing", "reject_short", "reject_non_hex", "reject_embedded")))
        self.assertTrue(catalog["reject_unrecognized"])
        self.assertEqual(full_system_status("D-040-S11", {"restored": True}), UNKNOWN)

        # Exercise the actual restore shell guard with an isolated, empty env:
        # no DATABASE_URL means it must refuse *before* any mysql/destructive call.
        run = subprocess.run(
            ["sh", str(RESTORE_CODE), "synthetic-no-dump.sql.gz"],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=10,
            env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
        )
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("falta DATABASE_URL", run.stderr)
        self.assertNotIn("Restauración verificada:", run.stdout)

    def test_sin_datos_reales(self) -> None:
        """AC-03: only tracked code, artificial values, no external I/O."""
        for path in (CI, TOKEN_CODE, PERMISSION_CODE, RESTORE_CODE):
            self.assertTrue(path.is_file(), str(path))
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", source)
        self.assertEqual({item[0] for item in DISPOSITION.values()}, {"A", "B"})
        self.assertEqual(full_system_status(None), UNKNOWN)

    def test_ci_gate_contract(self) -> None:
        """AC-04 machine target: assert gate wiring, not an invented green run."""
        source = CI.read_text(encoding="utf-8")
        self.assertIn("  validar:\n    name: Validar\n", source)
        self.assertIn('[[ "$FACTORY_CI" == "success" ]]', source)
        self.assertIn('[[ "$STATIC_ANALYSIS" == "success" ]]', source)
        self.assertIn('[[ "$RECTOR_DRY_RUN" == "success" ]]', source)
        self.assertIn('[[ "$AUDIT_COMPOSER" == "success" ]]', source)
        self.assertIn('[[ "$AUDIT_NPM" == "success" ]]', source)
        self.assertEqual(full_system_status("AC-04", "CI configured"), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
