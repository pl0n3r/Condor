#!/usr/bin/env bash
# Imprime la DATABASE_URL de la base desechable del laboratorio local
# (127.0.0.1:${CONDOR_RECOVERY_PORT}/condor_restore_test). Valida también una URL
# recibida por argumento: solo loopback, puerto del laboratorio, usuario
# condor_restore y base condor_restore_*. Nunca imprime otra cosa.
set -euo pipefail
lab_home="${CONDOR_RECOVERY_HOME:-$HOME/.condor-recovery-local}"
port="${CONDOR_RECOVERY_PORT:-3317}"
# El drill productivo solo restaura en el puerto fijo del laboratorio desechable.
[[ "$port" == "3317" ]] || { echo "disposable-url: puerto del drill inválido" >&2; exit 3; }
url="${1:-}"
if [[ -z "$url" ]]; then
  pw_file="$lab_home/secrets/app_password"
  [[ -s "$pw_file" ]] || { echo "disposable-url: laboratorio sin preparar (lab.sh up)" >&2; exit 1; }
  url="mysql://condor_restore:$(cat "$pw_file")@127.0.0.1:${port}/condor_restore_test?serverVersion=11.8.9-MariaDB&charset=utf8mb4"
fi
CONDOR_LAB_PORT="$port" python3 - "$url" <<'PY' || { echo "disposable-url: destino no permitido" >&2; exit 3; }
import os, re, sys
from urllib.parse import urlsplit
u = urlsplit(sys.argv[1])
ok = (u.scheme in ("mysql", "mariadb") and u.hostname in ("127.0.0.1", "localhost")
      and str(u.port) == os.environ["CONDOR_LAB_PORT"] and u.username == "condor_restore"
      and re.fullmatch(r"condor_restore_[a-z0-9_]{1,40}", u.path.lstrip("/") or "") is not None)
sys.exit(0 if ok else 3)
PY
printf '%s' "$url"
