#!/usr/bin/env python3
"""Guardado de ida y vuelta con valores raros en campos reales de módulos clave.

Para cada «receta» (crear + listar) cambia UN campo por un valor inesperado y exige:
  * nunca 5xx ni detalles internos;
  * si se rechaza (4xx): el mensaje dice qué está mal (no el genérico);
  * si se acepta (2xx): al volver a leer, el valor guardado es exactamente el enviado
    (o su forma canónica documentada) y no se pierde ni se corrompe Unicode.

Usa el mismo login que save_endpoints_fuzz.py. Datos sintéticos, base desechable.
"""
from __future__ import annotations

import json
import os
import sys
import uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import save_endpoints_fuzz as base  # noqa: E402

GENERIC = "Los datos enviados no son válidos."
TEXT_CASES = ["😀 Ñandú ÁÉÍÓÚ 🇨🇴", "مرحبا שלום", "<script>alert(1)</script>", "O'Brien \"Ltda\"",
              "'; DROP TABLE x; --", "   espacios   ", "", "a" * 5000, "a\u0000b", "{{7*7}}", "‮RTL"]
NUM_CASES = [-1, 0, 365, 366, 10**12, 1.5, "30", None, True, [], {}]


def uniq() -> str:
    return uuid.uuid4().hex[:8].upper()


RECIPES = [
    {
        "name": "Proveedores",
        "create": "/api/v1/branches/{branchId}/purchasing/suppliers",
        "list": "/api/v1/branches/{branchId}/purchasing/suppliers",
        "list_key": "suppliers",
        "base": lambda: {"code": "FZ-" + uniq(), "name": "Proveedor fuzz", "payment_terms_days": 30},
        "fields": {"name": TEXT_CASES, "code": TEXT_CASES, "payment_terms_days": NUM_CASES},
        "id_field": "code",
    },
]


def main() -> int:
    if not base.EMAIL or not base.PASSWORD:
        sys.exit("fuzz: define CONDOR_FUZZ_EMAIL y CONDOR_FUZZ_PASSWORD")
    c = base.Client()
    c.login()
    ctx = json.loads(c.req("GET", "/api/v1/context")[1])
    branch = (ctx.get("active_branch") or {}).get("id") or ""
    findings = []
    for r in RECIPES:
        create = r["create"].replace("{branchId}", branch)
        listing = r["list"].replace("{branchId}", branch)
        for field, values in r["fields"].items():
            for value in values:
                payload = r["base"]()
                payload[field] = value
                if field == r["id_field"] and isinstance(value, str):
                    payload[field] = value + "-" + uniq() if value.strip() else value
                sent = payload[field]
                status, body, secs = c.req("POST", create, json.dumps(payload, ensure_ascii=False).encode())
                label = f"{r['name']}.{field}={json.dumps(value, ensure_ascii=False)[:40]}"
                problem = base.classify(status, body, secs)
                if problem:
                    findings.append((label, problem, body[:160]))
                    continue
                if 400 <= status < 500:
                    try:
                        msg = json.loads(body).get("message", "")
                    except ValueError:
                        msg = ""
                    if msg == GENERIC:
                        findings.append((label, "rechazo sin decir qué campo falla", msg))
                    continue
                # Aceptado: debe leerse igual.
                _, lbody, _ = c.req("GET", listing)
                try:
                    items = json.loads(lbody).get(r["list_key"], [])
                except ValueError:
                    findings.append((label, "listado no JSON tras guardar", lbody[:160]))
                    continue
                key = payload[r["id_field"]]
                match = [i for i in items if i.get(r["id_field"]) in (key, str(key).strip().upper(), str(key).strip())]
                if not match:
                    findings.append((label, "aceptado pero no aparece al listar", f"buscado {str(key)[:40]}"))
                    continue
                stored = match[0].get(field)
                if isinstance(sent, str) and isinstance(stored, str):
                    if stored != sent and stored != sent.strip() and stored.upper() != sent.strip().upper():
                        findings.append((label, "guardado distinto de lo enviado",
                                         f"enviado={sent[:40]!r} guardado={stored[:40]!r}"))
                    if "\u0000" in stored or "‮" in stored:
                        findings.append((label, "guarda caracteres de control invisibles", repr(stored[:40])))
                elif stored != sent:
                    findings.append((label, "valor numérico/tipo alterado o aceptado sin validar",
                                     f"enviado={sent!r} guardado={stored!r}"))
    known = base.load_baseline()
    new = [(l, p, d) for l, p, d in findings if f"{l.split('=')[0]} | {p}" not in known]
    print(f"Casos con problema: {len(findings)} · nuevos (bloquean): {len(new)}")
    for label, problem, detail in findings:
        tag = "NUEVO" if (label, problem, detail) in new else "conocido"
        print(f"  {'✗' if tag == 'NUEVO' else '·'} [{tag}] {label}: {problem} — {detail}")
    return 1 if new else 0


if __name__ == "__main__":
    sys.exit(main())
