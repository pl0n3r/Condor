#!/usr/bin/env bash
# Ensayo LOCAL de backup → cifrado → SHA-256 → restauración → verificación de Condor.
#
# Reutiliza las piezas canónicas del workflow «Recovery production drill»:
#   scripts/backup-database.sh, scripts/recovery-production.py (encrypt/decrypt/checksum)
#   y scripts/verify-backup-restore.sh. No introduce otra arquitectura de recuperación.
#
# Solo datos sintéticos, solo el MariaDB del laboratorio (127.0.0.1:${CONDOR_RECOVERY_PORT}),
# solo bases condor_restore_*. Cualquier otro destino se rechaza antes de conectar.
# Esto NO valida la recuperación productiva (backup real + offsite con Object Lock).
set -euo pipefail
umask 077

repeat=1
if [ "${1:-}" = "--repeat" ]; then repeat="${2:?--repeat N}"; fi

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
lab_home="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}"
secrets="$lab_home/secrets"
port="${CONDOR_RECOVERY_PORT:-3317}"
seed_db="condor_restore_seed"
target_db="condor_restore_test"
tenant_slug="recovery-sentinel"
user_email="sentinel@recovery.invalid"
code_sha="$(git -C "$repo" rev-parse HEAD)"
code_dirty="$(git -C "$repo" status --porcelain --untracked-files=no | wc -l | tr -d ' ')"

for f in app_password encryption_passphrase; do
  [ -s "$secrets/$f" ] || { echo "drill: falta $secrets/$f; ejecuta lab.sh up" >&2; exit 1; }
done
app_password="$(cat "$secrets/app_password")"

lab_url() { # $1 = base
  printf 'mysql://condor_restore:%s@127.0.0.1:%s/%s?serverVersion=11.8.9-MariaDB&charset=utf8mb4' \
    "$app_password" "$port" "$1"
}

# Guarda anti-producción: host loopback, puerto del laboratorio, usuario y base permitidos.
assert_lab_url() {
  CONDOR_LAB_PORT="$port" python3 - "$1" <<'PY'
import os, re, sys
from urllib.parse import urlsplit
u = urlsplit(sys.argv[1])
ok = (u.scheme in ("mysql", "mariadb")
      and u.hostname in ("127.0.0.1", "localhost")
      and str(u.port) == os.environ["CONDOR_LAB_PORT"]
      and u.username == "condor_restore"
      and re.fullmatch(r"condor_restore_[a-z0-9_]{1,40}", u.path.lstrip("/") or "") is not None)
sys.exit(0 if ok else 3)
PY
}

sql() { # $1 = base (o vacío), resto = args
  local db="$1"; shift
  MYSQL_PWD="$app_password" mariadb --skip-ssl --protocol=TCP -h127.0.0.1 -P"$port" -ucondor_restore ${db:+"$db"} "$@"
}

now_ms() { python3 -c 'import time;print(int(time.time()*1000))'; }

run_once() {
  local run_id="$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 3)"
  local run_dir="$lab_home/runs/$run_id"
  local dr_home="$run_dir/home"           # HOME aislado: recovery-production.py usa $HOME/.condor-recovery
  local dr_root="$dr_home/.condor-recovery"
  mkdir -p "$dr_root" "$run_dir/backup"; chmod 700 "$run_dir" "$dr_home" "$dr_root"
  local log="$run_dir/drill.log"
  local results="$run_dir/checks.tsv"; : > "$results"
  check() { printf '%s\t%s\t%s\n' "$1" "$2" "${3:-}" >> "$results"; echo "[$2] $1 ${3:-}" | tee -a "$log"; }

  local seed_url target_url; seed_url="$(lab_url "$seed_db")"; target_url="$(lab_url "$target_db")"
  assert_lab_url "$seed_url" && assert_lab_url "$target_url"

  # 1. Base sintética con esquema real (migraciones Doctrine de Condor) + centinelas.
  local t0; t0=$(now_ms)
  sql "" -e "DROP DATABASE IF EXISTS \`$seed_db\`; CREATE DATABASE \`$seed_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  (cd "$repo" && DATABASE_URL="$seed_url" APP_ENV=prod APP_DEBUG=0 APP_SECRET="$(openssl rand -hex 32)" \
     CONDOR_EPHEMERAL_CACHE=1 php bin/console doctrine:migrations:migrate -n >>"$log" 2>&1)
  sql "$seed_db" <<SQL
INSERT INTO condor_tenant (id,name,slug,created_at) VALUES ('01RECOVERYSENTINEL00000000','Tenant centinela sintético','$tenant_slug',UTC_TIMESTAMP());
INSERT INTO condor_user (id,email,display_name,password_hash,roles,active,created_at)
  VALUES ('01RECOVERYSENTINELUSER0000','$user_email','Usuario centinela','!synthetic-not-a-hash','["ROLE_USER"]',1,UTC_TIMESTAMP());
INSERT INTO condor_tenant (id,name,slug,created_at)
  SELECT CONCAT('01SYNT', LPAD(seq,20,'0')), CONCAT('Tenant sintético ',seq), CONCAT('synthetic-',seq), UTC_TIMESTAMP() FROM seq_1_to_2000;
INSERT INTO condor_user (id,email,display_name,password_hash,roles,active,created_at)
  SELECT CONCAT('01SYNU', LPAD(seq,20,'0')), CONCAT('user',seq,'@synthetic.invalid'), CONCAT('Usuario ',seq), '!synthetic', '["ROLE_USER"]', 1, UTC_TIMESTAMP() FROM seq_1_to_5000;
SQL
  check seed_synthetic PASS "$(( $(now_ms) - t0 )) ms"

  # 2. Backup consistente con el script canónico (camino PDO, el que usa Hostinger).
  t0=$(now_ms); local backup_created_at; backup_created_at=$(date +%s)
  (cd "$repo" && BACKUP_DIR="$run_dir/backup" CONDOR_BACKUP_CLIENT=pdo DATABASE_URL="$seed_url" \
     sh scripts/backup-database.sh >>"$log" 2>&1)
  local plain; plain="$(find "$run_dir/backup" -maxdepth 1 -name 'condor-*.sql.gz' | head -1)"
  [ -n "$plain" ] && gzip -t "$plain"
  local backup_ms=$(( $(now_ms) - t0 )); check backup_pdo PASS "$backup_ms ms, $(wc -c <"$plain" | tr -d ' ') bytes"

  # 3. Cifrado AES-256-CBC/PBKDF2 (200k) con el script canónico; el claro se borra.
  t0=$(now_ms)
  mv "$plain" "$dr_root/plain.sql.gz"
  export CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE; CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE="$(cat "$secrets/encryption_passphrase")"
  (cd "$repo" && HOME="$dr_home" python3 scripts/recovery-production.py encrypt --source plain.sql.gz --target backup.enc >/dev/null)
  rm -f "$dr_root/plain.sql.gz"
  head -c 8 "$dr_root/backup.enc" | grep -q '^Salted__' || { check encrypt FAIL "cabecera"; return 1; }
  local checksum; checksum="$(cd "$repo" && HOME="$dr_home" python3 scripts/recovery-production.py checksum --artifact backup.enc \
     | python3 -c 'import json,sys;print(json.load(sys.stdin)["checksum_sha256"])')"
  printf '%s  backup.enc\n' "$checksum" > "$run_dir/backup.enc.sha256"
  check encrypt_sha256 PASS "$(( $(now_ms) - t0 )) ms"

  # 4-7. Restauración: SHA-256 antes de descifrar, recrear SOLO la base descartable, verificar.
  local incident_at started_at; incident_at=$(date +%s); started_at=$(now_ms)
  (cd "$dr_root" && shasum -a 256 -c "$run_dir/backup.enc.sha256" >/dev/null) || { check sha256_verify FAIL; return 1; }
  check sha256_verify PASS
  (cd "$repo" && HOME="$dr_home" python3 scripts/recovery-production.py decrypt --source backup.enc --target restored.sql.gz >/dev/null)
  (cd "$repo" && DATABASE_URL="$target_url" CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1 \
     CONDOR_RESTORE_EXPECT_TENANT_SLUG="$tenant_slug" CONDOR_RESTORE_EXPECT_USER_EMAIL="$user_email" \
     sh scripts/verify-backup-restore.sh "$dr_root/restored.sql.gz" >>"$log" 2>&1)
  check restore_verify_script PASS

  # Integridad: mismas tablas, mismas filas y CHECKSUM TABLE idéntico por tabla.
  local tables; tables="$(sql "$seed_db" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$seed_db' ORDER BY 1")"
  local n_tables; n_tables=$(printf '%s\n' "$tables" | grep -c .)
  local mismatches=0 rows=0
  for t in $tables; do
    a="$(sql "$seed_db" -N -e "CHECKSUM TABLE \`$t\` EXTENDED" | awk '{print $2}')"
    b="$(sql "$target_db" -N -e "CHECKSUM TABLE \`$t\` EXTENDED" | awk '{print $2}')"
    c="$(sql "$target_db" -N -e "SELECT COUNT(*) FROM \`$t\`")"; rows=$((rows + c))
    [ "$a" = "$b" ] || { mismatches=$((mismatches + 1)); echo "checksum distinto: $t" >>"$log"; }
  done
  local restored_tables; restored_tables="$(sql "$target_db" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$target_db'")"
  if [ "$mismatches" -eq 0 ] && [ "$restored_tables" = "$n_tables" ]; then
    check integrity_checksum_table PASS "$n_tables tablas, $rows filas"
  else
    check integrity_checksum_table FAIL "$mismatches tablas distintas"; return 1
  fi

  # Aplicación Condor contra la base restaurada: /health (schema_up_to_date) y smoke de /.
  local app_port=18087 app_secret; app_secret="$(openssl rand -hex 32)"
  (cd "$repo" && DATABASE_URL="$target_url" APP_SECRET="$app_secret" APP_ENV=prod APP_DEBUG=0 CONDOR_EPHEMERAL_CACHE=1 \
     php -S 127.0.0.1:$app_port -t public public/index.php >"$run_dir/app-server.log" 2>&1) &
  local server_pid=$!; disown "$server_pid" 2>/dev/null || true
  local health=""
  for _ in $(seq 1 30); do health="$(curl -fsS --max-time 5 http://127.0.0.1:$app_port/health 2>/dev/null)" && break; sleep 1; done
  local smoke_code; smoke_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1:$app_port/ || true)"
  kill "$server_pid" 2>/dev/null || true; pkill -f "php -S 127.0.0.1:$app_port" 2>/dev/null || true
  if python3 -c 'import json,sys; d=json.loads(sys.argv[1]); assert d.get("status")=="ok" and d.get("schema_up_to_date") is True' "$health" 2>/dev/null; then
    check app_health_schema PASS
  else check app_health_schema FAIL "$(printf '%s' "$health" | head -c 120)"; return 1; fi
  if [ "$smoke_code" = "200" ]; then check app_smoke_root PASS; else check app_smoke_root FAIL "HTTP $smoke_code"; return 1; fi
  local completed_at; completed_at=$(now_ms); local rto_ms=$((completed_at - started_at))
  check rto_local PASS "$rto_ms ms"

  # 8. Fallos controlados: cada uno DEBE ser rechazado sin tocar nada fuera del laboratorio.
  expect_fail() { local name="$1"; shift; if "$@" >>"$log" 2>&1; then check "$name" FAIL "no rechazado"; return 1; else check "$name" PASS "rechazado"; fi; }

  cp "$dr_root/backup.enc" "$dr_root/tampered.enc"
  printf '\x00' | dd of="$dr_root/tampered.enc" bs=1 seek=4096 conv=notrunc 2>/dev/null
  expect_fail fail_tampered_ciphertext sh -c "cd '$dr_root' && sed 's/backup.enc/tampered.enc/' '$run_dir/backup.enc.sha256' | shasum -a 256 -c -"
  expect_fail fail_wrong_passphrase sh -c "cd '$repo' && HOME='$dr_home' CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE=wrong-passphrase python3 scripts/recovery-production.py decrypt --source backup.enc --target wrongpass.sql.gz && gzip -t '$dr_root/wrongpass.sql.gz'"
  expect_fail fail_target_not_disposable sh -c "cd '$repo' && DATABASE_URL='$(lab_url condor)' CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1 CONDOR_RESTORE_EXPECT_TENANT_SLUG=$tenant_slug CONDOR_RESTORE_EXPECT_USER_EMAIL=$user_email sh scripts/verify-backup-restore.sh '$dr_root/restored.sql.gz'"
  expect_fail fail_missing_destructive_flag sh -c "cd '$repo' && DATABASE_URL='$target_url' CONDOR_RESTORE_EXPECT_TENANT_SLUG=$tenant_slug CONDOR_RESTORE_EXPECT_USER_EMAIL=$user_email sh scripts/verify-backup-restore.sh '$dr_root/restored.sql.gz'"
  expect_fail fail_guard_remote_host assert_lab_url "mysql://condor_restore:x@db.example.com:$port/condor_restore_test"
  expect_fail fail_guard_prod_db_name assert_lab_url "mysql://condor_restore:x@127.0.0.1:$port/condor"
  expect_fail fail_privilege_other_db sql "" -e "CREATE DATABASE condor_probe_forbidden"
  head -c 2048 "$dr_root/restored.sql.gz" > "$dr_root/truncated.sql.gz"
  expect_fail fail_truncated_backup sh -c "cd '$repo' && DATABASE_URL='$(lab_url condor_restore_truncated)' CONDOR_ALLOW_DESTRUCTIVE_RESTORE=1 CONDOR_RESTORE_EXPECT_TENANT_SLUG=$tenant_slug CONDOR_RESTORE_EXPECT_USER_EMAIL=$user_email sh scripts/verify-backup-restore.sh '$dr_root/truncated.sql.gz'"
  # Tras los fallos, la base restaurada buena sigue intacta.
  local still; still="$(sql "$target_db" -N -e "SELECT COUNT(*) FROM condor_tenant WHERE slug='$tenant_slug'")"
  [ "$still" = "1" ] && check target_intact_after_failures PASS || { check target_intact_after_failures FAIL; return 1; }
  sql "" -e "DROP DATABASE IF EXISTS condor_restore_truncated" >/dev/null 2>&1 || true

  # Limpieza de artefactos en claro; se conserva solo el cifrado + su SHA-256.
  rm -f "$dr_root/restored.sql.gz" "$dr_root/tampered.enc" "$dr_root/truncated.sql.gz" "$dr_root/wrongpass.sql.gz"

  # 9. Evidencia sanitizada (sin secretos, sin URLs con credenciales).
  python3 - "$run_dir" <<PY
import json, sys, csv, platform, subprocess
from pathlib import Path
run = Path(sys.argv[1])
checks = [dict(name=r[0], result=r[1], detail=r[2] if len(r) > 2 else "") for r in csv.reader(open(run/"checks.tsv"), delimiter="\t")]
server = subprocess.run(["docker","exec","condor-recovery-mariadb","mariadb","--version"],capture_output=True,text=True).stdout.strip()
ev = {
  "run_id": "$run_id", "environment": "local-lab (macOS $(sw_vers -productVersion) $(uname -m), Docker/Colima)",
  "code_sha": "$code_sha", "code_tracked_changes": int("$code_dirty"),
  "mariadb_image": "mariadb:11.8@sha256:e1d5495f445654134f226f55a41ce0da227116f6f27f283101f405c794d841fc",
  "mariadb_server": server, "data": "synthetic-only", "source_db": "$seed_db", "target_db": "$target_db",
  "backup_method": "scripts/backup-database.sh (PDO)", "encryption": "openssl aes-256-cbc pbkdf2 200000 sha256 (scripts/recovery-production.py)",
  "ciphertext_sha256": "$checksum", "backup_created_at": $backup_created_at, "incident_at": $incident_at,
  "backup_ms": $backup_ms, "rto_ms": $rto_ms, "tables": $n_tables,
  "checks": checks, "result": "PASSED" if all(c["result"]=="PASS" for c in checks) else "FAILED",
  "scope_note": "Ensayo local con datos sintéticos. No acredita backup productivo, offsite con Object Lock ni RPO/RTO de producción.",
}
(run/"evidence.json").write_text(json.dumps(ev, indent=2, ensure_ascii=False), encoding="utf-8")
print(json.dumps({k: ev[k] for k in ("run_id","result","rto_ms","backup_ms","tables","ciphertext_sha256")}))
PY
}

status=0
for i in $(seq 1 "$repeat"); do run_once || status=1; done
exit $status
