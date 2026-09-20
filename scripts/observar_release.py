#!/usr/bin/env python3
"""Observa una release de Condor sin escribir ni autenticar contra producción."""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
from html.parser import HTMLParser
from typing import Any
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

DOMINIO_PRODUCCION = "https://www.condorapp.com.co"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
VERSION_PATTERN = re.compile(r"\d+\.\d+\.\d+\Z", re.ASCII)
MAX_BYTES = 256 * 1024


class ObservacionError(Exception):
    """Error presentable sin detalles de red ni datos sensibles."""


class NoRedirigir(HTTPRedirectHandler):
    def redirect_request(self, request: Request, fp: Any, code: int,
                         msg: str, headers: Any, newurl: str) -> None:
        return None


class TextoVisible(HTMLParser):
    """Extrae texto visible y campos del login, excluyendo scripts y estilos."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.oculto = 0
        self.textos: list[str] = []
        self.en_formulario = 0
        self.campos: set[tuple[str, str]] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in {"script", "style", "template"}:
            self.oculto += 1
        elif tag == "form":
            self.en_formulario += 1
        elif tag == "input" and self.en_formulario:
            atributos = dict(attrs)
            self.campos.add((atributos.get("name") or "", atributos.get("type") or "text"))

    def handle_endtag(self, tag: str) -> None:
        if tag in {"script", "style", "template"}:
            self.oculto = max(0, self.oculto - 1)
        elif tag == "form":
            self.en_formulario = max(0, self.en_formulario - 1)

    def handle_data(self, data: str) -> None:
        if not self.oculto:
            self.textos.append(data)


def validar_base_url(valor: str, permitir_http_local: bool = False) -> str:
    """Evita URLs ambiguas, credenciales en URI y HTTP remoto."""
    url = urlsplit(valor)
    try:
        puerto = url.port
    except ValueError as error:
        raise ObservacionError("Puerto no válido en la URL base.") from error

    if (not url.hostname or not url.netloc or url.username is not None
            or url.password is not None or url.path not in ("", "/")
            or url.query or url.fragment or puerto == 0):
        raise ObservacionError("La URL base debe contener solo origen y puerto, sin credenciales ni ruta.")
    if url.scheme == "https":
        return valor.rstrip("/")
    if (url.scheme == "http" and permitir_http_local
            and url.hostname in {"localhost", "127.0.0.1", "::1"}):
        return valor.rstrip("/")
    raise ObservacionError("Se requiere HTTPS; HTTP solo se permite en loopback explícito.")


def obtener(origen: str, ruta: str, timeout: float) -> tuple[str, bytes]:
    """GET sin redirecciones, cookies ni credenciales, con cuerpo limitado."""
    solicitud = Request(
        origen + ruta,
        headers={
            "Accept": (
                "application/json" if ruta == "/health"
                else "text/css" if ruta.endswith(".css")
                else "text/javascript, application/javascript" if ruta.endswith(".js")
                else "text/html"
            ),
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
            "User-Agent": "Condor-Release-Observer/0.1",
        },
        method="GET",
    )
    try:
        with build_opener(NoRedirigir).open(solicitud, timeout=timeout) as respuesta:
            if respuesta.status != 200:
                raise ObservacionError(f"HTTP {respuesta.status}; se esperaba 200.")
            contenido = respuesta.read(MAX_BYTES + 1)
            if len(contenido) > MAX_BYTES:
                raise ObservacionError("La respuesta supera el límite permitido.")
            return respuesta.headers.get_content_type(), contenido
    except HTTPError as error:
        if 300 <= error.code < 400:
            raise ObservacionError(f"Redirección HTTP {error.code} no permitida.") from error
        raise ObservacionError(f"HTTP {error.code}; se esperaba 200.") from error
    except OSError as error:
        raise ObservacionError("No se recibió una respuesta HTTP válida dentro del tiempo permitido.") from error


def validar_health(tipo: str, cuerpo: bytes, version: str, sha: str) -> None:
    if tipo != "application/json":
        raise ObservacionError("/health no respondió con JSON.")
    try:
        carga = json.loads(cuerpo.decode("utf-8"))
    except ValueError as error:
        raise ObservacionError("/health devolvió JSON inválido.") from error
    if not isinstance(carga, dict) or carga.get("status") != "ok":
        raise ObservacionError("/health no informa estado ok.")
    if carga.get("version") != version:
        raise ObservacionError("La versión observada no coincide con la esperada.")
    if carga.get("release_sha") != sha:
        raise ObservacionError("El SHA observado no coincide con el esperado.")


def validar_pagina(tipo: str, cuerpo: bytes, version: str, login: bool) -> None:
    if tipo != "text/html":
        raise ObservacionError("La página no respondió con HTML.")
    analizador = TextoVisible()
    try:
        analizador.feed(cuerpo.decode("utf-8"))
        analizador.close()
    except ValueError as error:
        raise ObservacionError("La página devolvió HTML no válido para esta comprobación.") from error

    texto = " ".join(analizador.textos)
    if not re.search(rf"(?<!\w)V\s+{re.escape(version)}(?!\d)", texto):
        raise ObservacionError("La página no muestra la versión de release esperada.")
    if login and not {("_username", "email"), ("_password", "password")}.issubset(analizador.campos):
        raise ObservacionError("El login administrativo no contiene su formulario esperado.")


def validar_asset(tipo: str, cuerpo: bytes, ruta: str) -> None:
    """Comprueba que el asset servido no sea una página fallback, vacío ni MIME erróneo."""
    esperados = (
        {"text/css"} if ruta.endswith(".css")
        else {"text/javascript", "application/javascript"}
    )
    if tipo not in esperados:
        raise ObservacionError("El recurso estático no tiene el tipo de contenido esperado.")
    if not cuerpo.strip():
        raise ObservacionError("El recurso estático está vacío.")


def observar(origen: str, version: str, sha: str, *, intentos: int = 3,
            intervalo: float = 2, timeout: float = 5) -> dict[str, Any]:
    """Solo la identidad exacta permite pasar de NO_OBSERVADO a DEPLOY_OBSERVED."""
    evidencias: dict[str, dict[str, Any]] = {}
    for intento in range(1, intentos + 1):
        try:
            tipo, cuerpo = obtener(origen, "/health", timeout)
            validar_health(tipo, cuerpo, version, sha)
        except ObservacionError as error:
            evidencias["health"] = {"ok": False, "detalle": str(error), "intento": intento}
            if intento < intentos:
                time.sleep(intervalo)
            continue
        evidencias["health"] = {"ok": True, "detalle": "Versión y SHA exactos confirmados.", "intento": intento}
        break
    else:
        return {"estado": "NO_OBSERVADO", "version_esperada": version,
                "sha_esperado": sha, "comprobaciones": evidencias}

    for nombre, ruta, es_login in [
        ("home", "/", False), ("admin_login", "/admin/login", True),
    ]:
        try:
            tipo, cuerpo = obtener(origen, ruta, timeout)
            validar_pagina(tipo, cuerpo, version, es_login)
            evidencias[nombre] = {"ok": True, "detalle": "HTTP 200, HTML y versión visibles."}
        except ObservacionError as error:
            evidencias[nombre] = {"ok": False, "detalle": str(error)}

    for nombre, ruta in [
        ("css_publico", "/app.css"),
        ("css_admin", "/build/admin.css"),
        ("js_admin", "/build/admin.js"),
    ]:
        try:
            tipo, cuerpo = obtener(origen, ruta, timeout)
            validar_asset(tipo, cuerpo, ruta)
            evidencias[nombre] = {
                "ok": True,
                "detalle": "HTTP 200 y contenido estático válido.",
            }
        except ObservacionError as error:
            evidencias[nombre] = {"ok": False, "detalle": str(error)}

    valido = all(item["ok"] for item in evidencias.values())
    return {"estado": "VALIDATED_IN_PRODUCTION" if valido else "DEPLOY_OBSERVED",
            "version_esperada": version, "sha_esperado": sha,
            "comprobaciones": evidencias}


def resumen(resultado: dict[str, Any]) -> str:
    lineas = [
        "## Observación de release Condor",
        "",
        f"- Estado: **{resultado['estado']}**",
        "- Versión esperada: "+ "`" + resultado["version_esperada"] + "`",
        "- SHA esperado: "+ "`" + resultado["sha_esperado"] + "`",
        "",
        "| Comprobación | Resultado | Detalle |",
        "| --- | --- | --- |",
    ]
    for nombre, item in resultado["comprobaciones"].items():
        icono = "✅" if item["ok"] else "❌"
        lineas.append("| "+ "`"+nombre+"`"+f" | {icono} | {item['detalle']} |")
    if resultado["estado"] != "VALIDATED_IN_PRODUCTION":
        lineas.extend(["", "La producción no se declara validada con esta evidencia."])
    return "\n".join(lineas) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True, help="Versión esperada, p. ej. 0.1.0")
    parser.add_argument("--sha", required=True, help="SHA exacto de 40 caracteres del merge")
    parser.add_argument("--intentos", type=int, default=3)
    parser.add_argument("--intervalo", type=float, default=2)
    parser.add_argument("--timeout", type=float, default=5)
    parser.add_argument("--markdown", action="store_true", help="Salida para el Job Summary")
    args = parser.parse_args(argv)

    try:
        origen = DOMINIO_PRODUCCION
        if not VERSION_PATTERN.fullmatch(args.version):
            raise ObservacionError("La versión debe tener formato X.Y.Z.")
        if not SHA_PATTERN.fullmatch(args.sha):
            raise ObservacionError("El SHA esperado debe tener exactamente 40 caracteres hexadecimales minúsculos.")
        if not 1 <= args.intentos <= 10 or not 0 <= args.intervalo <= 60 or not 0.1 <= args.timeout <= 30:
            raise ObservacionError("Intentos (1-10), intervalo (0-60) o timeout (0.1-30) fuera de rango.")
    except ObservacionError as error:
        parser.error(str(error))

    resultado = observar(origen, args.version, args.sha, intentos=args.intentos,
                        intervalo=args.intervalo, timeout=args.timeout)
    reporte = json.dumps(resultado, ensure_ascii=False, indent=2) + "\n"
    print(resumen(resultado) if args.markdown else reporte, end="")
    return 0 if resultado["estado"] == "VALIDATED_IN_PRODUCTION" else 1


if __name__ == "__main__":
    sys.exit(main())
