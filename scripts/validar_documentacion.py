#!/usr/bin/env python3
"""Validaciones ligeras y deterministas para la documentación de Cóndor."""

from __future__ import annotations

import re
import sys
from pathlib import Path
from urllib.parse import unquote

RAIZ = Path(__file__).resolve().parent.parent

REQUERIDOS = (
    "AGENTES.md",
    "ESPECIFICACIONES.md",
    "GLOSARIO.md",
    "README.md",
    "ROADMAP.md",
)

PATRON_ENLACE = re.compile(r"\[[^\]]*\]\(([^)]+)\)")


def limpiar_destino(valor: str) -> str:
    destino = valor.strip().strip("<>")
    if " " in destino and not destino.startswith(("http://", "https://")):
        destino = destino.split(" ", 1)[0]
    destino = destino.split("#", 1)[0].split("?", 1)[0]
    return unquote(destino)


def validar_archivos_requeridos(errores: list[str]) -> None:
    for relativo in REQUERIDOS:
        if not (RAIZ / relativo).is_file():
            errores.append(f"Falta el archivo canónico {relativo}.")

    if (RAIZ / "AGENTS.md").exists():
        errores.append("AGENTS.md no debe existir; el archivo canónico es AGENTES.md.")


def validar_enlaces_relativos(errores: list[str]) -> None:
    for archivo in RAIZ.rglob("*.md"):
        if ".git" in archivo.parts:
            continue

        texto = archivo.read_text(encoding="utf-8")
        for coincidencia in PATRON_ENLACE.finditer(texto):
            destino = limpiar_destino(coincidencia.group(1))
            if not destino or destino.startswith(("#", "http://", "https://", "mailto:")):
                continue

            if destino.startswith("/"):
                objetivo = RAIZ / destino.lstrip("/")
            else:
                objetivo = archivo.parent / destino

            if not objetivo.exists():
                relativo = archivo.relative_to(RAIZ)
                errores.append(
                    f"{relativo}: enlace relativo roto '{coincidencia.group(1)}'."
                )


def main() -> int:
    errores: list[str] = []
    validar_archivos_requeridos(errores)
    validar_enlaces_relativos(errores)

    if errores:
        print("Validación de documentación fallida:", file=sys.stderr)
        for error in errores:
            print(f"- {error}", file=sys.stderr)
        return 1

    print("Documentación validada correctamente.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
