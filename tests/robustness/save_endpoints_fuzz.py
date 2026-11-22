#!/usr/bin/env python3
"""Robustez de guardado: envía entradas rotas o inesperadas a TODOS los endpoints
POST/PUT/PATCH de la API autenticada de Condor y falla si alguno responde 5xx,
filtra trazas internas o deja de responder JSON.

Descubre los endpoints solo (`bin/console debug:router`), así que un módulo nuevo
queda cubierto sin tocar esta prueba. Usa una base desechable y datos sintéticos.

Uso:
  CONDOR_FUZZ_BASE_URL=http://127.0.0.1:8091 \
  CONDOR_FUZZ_EMAIL=admin@andina.test CONDOR_FUZZ_PASSWORD=... \
  python3 tests/robustness/save_endpoints_fuzz.py [--json informe.json]
El router se lee con el mismo entorno (DATABASE_URL, APP_SECRET…) que el servidor.
"""
from __future__ import annotations

import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# The client holds an ephemeral CI owner password: never allow remote targets.
BASE = "http://127.0.0.1:8091"
if os.environ.get("CONDOR_FUZZ_BASE_URL", BASE).rstrip("/") != BASE:
    raise SystemExit("fuzz: destino rechazado, solo HTTP loopback local 127.0.0.1:8091")
EMAIL = os.environ.get("CONDOR_FUZZ_EMAIL", "")
PASSWORD = os.environ.get("CONDOR_FUZZ_PASSWORD", "")
# Exclusions are deliberately not configurable to avoid quietly omitting writes.
SKIP = re.compile(r"^/api/public/|/logout")
LEAK = re.compile(r"(Stack trace|#\d+ /|\bat [\w\\]+->|Uncaught|Fatal error|SQLSTATE|PDOException|"
                  r"Doctrine\\|vendor/|/var/www|/home/\w+/|Symfony\\Component|Traceback)", re.I)

# Valores raros que una persona puede escribir o pegar en cualquier campo.
WEIRD_STRINGS = [
    "", "   ", "\t\n", "a" * 100_000, "😀🇨🇴𝔘𝔫𝔦𝔠𝔬𝔡𝔢", "Ñandú ÁÉÍÓÚ ü", "مرحبا שלום",
    "<script>alert(1)</script>", "\"'><img src=x onerror=alert(1)>", "'; DROP TABLE condor_user; --",
    "../../etc/passwd", "a\u0000b", "‮RTL", "null", "NaN", "-1", "9" * 40, "1e309",
    "2026-02-30", "0000-00-00", "{{7*7}}", "${7*7}", "%s%s%n",
]
WEIRD_VALUES = WEIRD_STRINGS + [None, True, False, 0, -1, -0.0001, 2**63, -(2**63), 1e308, 3.14159,
                                [], {}, [None], {"$ne": None}, {"a": {"b": {"c": {"d": {}}}}}]

# Cuerpos que no son lo que el endpoint espera.
RAW_BODIES = [
    ("json_roto", b'{"name": "x",', "application/json"),
    ("no_json", b"name=x&price=1", "application/x-www-form-urlencoded"),
    ("vacio", b"", "application/json"),
    ("array_raiz", b"[]", "application/json"),
    ("null_raiz", b"null", "application/json"),
    ("texto", b"hola", "text/plain"),
    ("utf8_invalido", b'{"name": "\xff\xfe"}', "application/json"),
    ("anidado_profundo", ("[" * 5000 + "]" * 5000).encode(), "application/json"),
    ("enorme", json.dumps({"name": "x" * 2_000_000}).encode(), "application/json"),
]


class LoopbackOnlyRedirect(urllib.request.HTTPRedirectHandler):
    """Accept only same-origin redirects; never forward the CI session off host."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        if (target.scheme, target.hostname, target.port) != ("http", "127.0.0.1", 8091):
            raise urllib.error.HTTPError(req.full_url, 403, "fuzz: redirect externo rechazado", headers, fp)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Client:
    def __init__(self) -> None:
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), LoopbackOnlyRedirect(),
            urllib.request.HTTPCookieProcessor(self.jar),
        )
        self.csrf = ""

    def req(self, method: str, path: str, body: bytes | None = None, ctype: str = "application/json",
            extra: dict | None = None, timeout: float = 30) -> tuple[int, str, float]:
        headers = {"Accept": "application/json", "Content-Type": ctype}
        if self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        headers.update(extra or {})
        r = urllib.request.Request(BASE + path, data=body, method=method, headers=headers)
        t0 = time.monotonic()
        try:
            with self.opener.open(r, timeout=timeout) as resp:
                return resp.status, resp.read(200_000).decode("utf-8", "replace"), time.monotonic() - t0
        except urllib.error.HTTPError as e:
            return e.code, e.read(200_000).decode("utf-8", "replace"), time.monotonic() - t0
        except Exception as e:  # timeout o conexión caída = fallo grave
            return 0, f"{type(e).__name__}: {e}", time.monotonic() - t0

    def login(self) -> None:
        _, html, _ = self.req("GET", "/admin/login", ctype="text/html")
        token = re.search(r'name="_csrf_token" value="([^"]+)"', html)
        form = urllib.parse.urlencode({"_username": EMAIL, "_password": PASSWORD,
                                       "_csrf_token": token.group(1) if token else ""}).encode()
        self.req("POST", "/admin/login", form, "application/x-www-form-urlencoded")
        _, page, _ = self.req("GET", "/admin", ctype="text/html")
        m = re.search(r'data-access-token="([^"]+)"', page)
        if not m:
            sys.exit("fuzz: no se pudo iniciar sesión o leer el token CSRF de /admin")
        self.csrf = m.group(1)


BASELINE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "known_findings.json")


def load_baseline(path: str | None = None) -> set[str]:
    """Fail closed if the exception ledger is missing, malformed or untraceable."""
    with open(path or BASELINE, encoding="utf-8") as fh:
        document = json.load(fh)
    entries = document.get("known")
    if not isinstance(entries, list):
        raise ValueError("known_findings.json: 'known' debe ser una lista")
    keys: set[str] = set()
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"endpoint", "problema", "issue"}:
            raise ValueError("known_findings.json: exige endpoint/problema/issue")
        if any(not isinstance(entry[key], str) or not entry[key].strip()
               for key in ("endpoint", "problema", "issue")):
            raise ValueError("known_findings.json: entradas vacías o inválidas")
        if not re.fullmatch(r"#[1-9][0-9]*", entry["issue"]):
            raise ValueError("known_findings.json: issue debe ser #N")
        signature = f"{entry['endpoint']} | {entry['problema']}"
        if signature in keys:
            raise ValueError("known_findings.json: firmas duplicadas")
        keys.add(signature)
    return keys


def discover_routes() -> list[tuple[str, str]]:
    out = subprocess.run(["php", "bin/console", "debug:router", "--format=json"], cwd=ROOT,
                         capture_output=True, text=True, check=True).stdout
    routes = []
    for r in json.loads(out).values():
        raw_methods = r.get("method") or "ANY"
        method_tokens = raw_methods if isinstance(raw_methods, list) else str(raw_methods).split("|")
        methods = {str(method).strip().upper() for method in method_tokens}
        path = r["path"]
        if path.startswith("/api/") and not SKIP.search(path):
            routes.extend((method, path) for method in ("POST", "PUT", "PATCH") if method in methods)
    return sorted(set(routes))


def fill(path: str, ctx: dict) -> str:
    def sub(m: re.Match) -> str:
        name = m.group(1)
        return ctx.get(name) or ("01ZZZZZZZZZZZZZZZZZZZZZZZZ" if name.lower().endswith("id") else "fuzz-inexistente")
    return re.sub(r"\{(\w+)\}", sub, path)


def classify(status: int, body: str, secs: float) -> str | None:
    if status == 0:
        return "sin respuesta / timeout"
    if status >= 500:
        return f"HTTP {status}"
    if LEAK.search(body):
        return "filtra detalles internos"
    if secs > 15:
        return f"lento ({secs:.0f}s)"
    if status not in (204, 302) and body.strip() and not body.lstrip().startswith(("{", "[")):
        return f"respuesta no JSON (HTTP {status})"
    return None


def main() -> int:
    if not EMAIL or not PASSWORD:
        sys.exit("fuzz: define CONDOR_FUZZ_EMAIL y CONDOR_FUZZ_PASSWORD")
    c = Client()
    c.login()
    _, ctx_body, _ = c.req("GET", "/api/v1/context")
    try:
        ctx_json = json.loads(ctx_body)
        branch = (ctx_json.get("active_branch") or {}).get("id") or ""
    except ValueError:
        branch = ""
    ctx = {"branchId": branch}
    routes = discover_routes()
    if not routes:
        print("fuzz: no se descubrieron rutas de escritura /api; falla cerrado", file=sys.stderr)
        return 1
    findings = []
    for method, raw_path in routes:
        path = fill(raw_path, ctx)
        cases = [(n, b, t) for n, b, t in RAW_BODIES]
        # Mismo payload de "campos raros" en nombres de campo comunes de todos los módulos.
        for i, v in enumerate(WEIRD_VALUES):
            payload = {k: v for k in ("name", "title", "description", "email", "price", "quantity",
                                      "amount", "date", "status", "slug", "sku", "notes", "reason")}
            cases.append((f"campos_raros_{i}", json.dumps(payload, ensure_ascii=False).encode(), "application/json"))
        for name, body, ctype in cases:
            status, resp, secs = c.req(method, path, body, ctype)
            problem = classify(status, resp, secs)
            if problem:
                findings.append({"endpoint": f"{method} {raw_path}", "caso": name, "problema": problem,
                                 "status": status, "extracto": resp[:240]})
    known = load_baseline()
    new = [f for f in findings if f"{f['endpoint']} | {f['problema']}" not in known]
    report = {"endpoints": len(routes), "fallos": len(findings), "nuevos": len(new), "detalle": findings}
    if "--json" in sys.argv:
        with open(sys.argv[sys.argv.index("--json") + 1], "w", encoding="utf-8") as fh:
            json.dump(report, fh, ensure_ascii=False, indent=2)
    by_endpoint: dict[str, list] = {}
    for f in findings:
        by_endpoint.setdefault(f["endpoint"], []).append(f)
    print(f"Endpoints probados: {len(routes)} · con fallos: {len(by_endpoint)} · casos fallidos: {len(findings)} · nuevos (bloquean): {len(new)}")
    for ep, fs in sorted(by_endpoint.items()):
        kinds = sorted({f["problema"] for f in fs})
        tag = "conocido" if all(f"{ep} | {k}" in known for k in kinds) else "NUEVO"
        print(f"  {'·' if tag == 'conocido' else '✗'} [{tag}] {ep}: {len(fs)} casos — {', '.join(kinds)} (p. ej. {fs[0]['caso']})")
    return 1 if new else 0


if __name__ == "__main__":
    sys.exit(main())
