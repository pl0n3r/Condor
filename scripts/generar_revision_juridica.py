#!/usr/bin/env python3
"""Package legal source files verbatim; never claim legal approval."""
from __future__ import annotations
import argparse
import datetime
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEST = ROOT / "revision-juridica"
STATE = "documented_not_legally_approved"
SOURCES = (
    ("docs/privacidad/aviso-privacidad.md", "aviso-privacidad.md", "identidad, consentimiento y finalidades"),
    ("docs/privacidad/canal-derechos.md", "canal-derechos.md", "contacto, plazos y ejercicio de derechos"),
    ("docs/privacidad/politica-tratamiento.md", "politica-tratamiento.md", "bases, finalidades, destinatarios y garantías"),
    ("docs/privacidad/registro-tratamientos.md", "registro-tratamientos.md", "categorías, datos, usos y proveedores"),
    ("docs/privacidad/retencion.md", "retencion.md", "períodos de conservación y eliminación"),
    ("docs/privacidad/terminos-condiciones.md", "terminos-condiciones.md", "obligaciones, niveles de servicio y reclamaciones"),
    ("docs/arquitectura-entidades-legales.md", "arquitectura-entidades-legales.md", "titularidad y relaciones interempresa"),
)
FIRST_LINE = re.compile(
    r"\AÚltima actualización automática: ([0-9]{4}-[0-9]{2}-[0-9]{2}) "
    r"· SHA de origen: ([0-9a-f]{40})\n"
)

def metadata(check: bool) -> tuple[str, str]:
    path = DEST / "00-LEEME.md"
    if check and path.is_file():
        hit = FIRST_LINE.match(path.read_text(encoding="utf-8"))
        if hit:
            return hit.group(1), hit.group(2)
    date = datetime.datetime.now(datetime.timezone.utc).date().isoformat()
    proc = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "HEAD"],
                          text=True, capture_output=True, check=False)
    sha = proc.stdout.strip().lower()
    if proc.returncode or not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("No hay SHA Git auténtico del origen")
    return date, sha

def version() -> str:
    data = (ROOT / "config/version.php").read_text(encoding="utf-8")
    match = re.search(r"['\"]version['\"]\s*=>\s*['\"]([0-9.]+)['\"]", data)
    if not match:
        raise ValueError("No hay versión de producto verificable")
    return match.group(1)

def processors() -> tuple[str, ...]:
    data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
    providers = set()
    for treatment in data.get("treatments", []):
        if not isinstance(treatment, dict):
            continue
        for item in treatment.get("providers", []):
            if isinstance(item, str) and item.strip() and item != "ninguno_declarado":
                providers.add(item.strip())
    return tuple(sorted(providers))

def render_index(date: str, sha: str, product_version: str,
                 providers: tuple[str, ...]) -> bytes:
    lines = [
        f"Última actualización automática: {date} · SHA de origen: {sha}\n",
        "\n# Documentos para revisión jurídica — Condor\n\n",
        f"Estado literal: {STATE}. Sin aprobación jurídica.\n",
        f"Versión del producto: {product_version}. Commit del repositorio de origen: {sha}.\n",
        "Las copias preservan byte a byte los documentos fuente. Este paquete no autoriza ",
        "go-live, publicación ni tratamiento adicional de datos.\n\n",
        "## Documentos que debe comprobar o corregir el abogado\n\n",
    ]
    for source, name, question in SOURCES:
        lines.append(f"- [{name}]({name}): copia literal de {source}; validar {question}.\n")
    lines += [
        "- [paquete-completo.md](paquete-completo.md): los siete documentos íntegros, ",
        "concatenados bajo encabezados para entregar un único archivo.\n\n",
        "## Hechos del responsable pendientes de confirmar (sin datos reales)\n\n",
        "- [ ] Razón social o nombre jurídico del responsable.\n",
        "- [ ] Identificación, NIT e inscripción, si corresponden.\n",
        "- [ ] Dirección de notificaciones y domicilio jurídico.\n",
        "- [ ] Canal de derechos (correo o medio autorizado y procedimiento).\n",
        "- [ ] Identidad y autoridad del representante para suscribir documentos.\n",
        "- [ ] Plazos de retención, bases legales, consentimiento y revocación.\n",
        "- [ ] Transferencias internacionales, contratos y roles de terceros.\n\n",
        "## Proveedores declarados e infraestructura por confirmar\n\n",
    ]
    if providers:
        for provider in providers:
            lines.append(f"- {provider}: declarado en datos.yml; validar rol jurídico, "
                         "contrato, subencargo y posibles transferencias.\n")
    else:
        lines.append("- Ningún proveedor declarado en datos.yml; verificar exhaustividad.\n")
    lines += [
        "- Hostinger: hosting compartido declarado en AGENTES.md; confirmar ",
        "contratante, rol y ubicación de datos. No se infiere su calidad de subencargado.\n",
        "- GitHub Actions: automatización del repositorio; verificar si accede a ",
        "datos personales reales antes de considerarlo subprocesador.\n\n",
        "Revisión por abogado obligatoria antes de live. Generar con ",
        "python scripts/generar_revision_juridica.py; verificar sin modificaciones con ",
        "python scripts/generar_revision_juridica.py --check.\n",
    ]
    return "".join(lines).encode("utf-8")

def render_package(contents: dict[str, bytes]) -> bytes:
    parts = [
        "# Paquete consolidado para revisión jurídica — Condor\n\n",
        f"Estado: {STATE}. Documentos fuente íntegros a continuación.\n\n",
    ]
    for source, _name, _question in SOURCES:
        raw = contents[source]
        parts.append(f"---\n\n## Documento fuente: {source}\n\n")
        parts.append(raw.decode("utf-8"))
        if not raw.endswith(b"\n"):
            parts.append("\n")
        parts.append("\n")
    return "".join(parts).encode("utf-8")

def expected(check: bool) -> dict[str, bytes]:
    contents = {source: (ROOT / source).read_bytes() for source, _, _ in SOURCES}
    date, sha = metadata(check)
    result = {name: contents[source] for source, name, _ in SOURCES}
    result["00-LEEME.md"] = render_index(date, sha, version(), processors())
    result["paquete-completo.md"] = render_package(contents)
    return result

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Comparar sin escribir")
    args = parser.parse_args()
    try:
        outputs = expected(args.check)
    except (OSError, ValueError, UnicodeError) as exc:
        print(f"Fuentes jurídicas no verificables: {exc}", file=sys.stderr)
        return 2
    stale = [
        name for name, data in outputs.items()
        if not (DEST / name).is_file() or (DEST / name).read_bytes() != data
    ]
    unexpected = sorted(
        p.name for p in DEST.iterdir()
        if p.is_file() and p.name not in outputs and p.name != "PAQUETE-ABOGADO.md"
    ) if DEST.is_dir() else []
    if args.check:
        if stale or unexpected:
            print("Paquete jurídico desactualizado: "
                  + ", ".join(stale + unexpected), file=sys.stderr)
            return 1
        print("Paquete jurídico verificado; no implica aprobación legal.")
        return 0
    if unexpected:
        print("No borrar archivos desconocidos: " + ", ".join(unexpected),
              file=sys.stderr)
        return 2
    DEST.mkdir(parents=True, exist_ok=True)
    for name, data in outputs.items():
        (DEST / name).write_bytes(data)
    print("Paquete jurídico generado; sin aprobación legal.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
