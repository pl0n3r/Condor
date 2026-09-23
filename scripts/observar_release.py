#!/usr/bin/env python3
"""Observa una release de Condor sin escribir ni autenticar contra producción."""

from __future__ import annotations

import argparse
import json
import re
import socket
import ssl
import sys
import time
from collections.abc import Callable
from html.parser import HTMLParser
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

DOMINIO_PRODUCCION = "https://www.condorapp.com.co"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
VERSION_PATTERN = re.compile(r"\d+\.\d+\.\d+\Z", re.ASCII)
TENANT_SLUG_PATTERN = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)*\Z", re.ASCII)
STOREFRONT_VERSION = (0, 1, 13)
STOREFRONT_IDENTITY_VERSION = (0, 1, 16)
MAX_BYTES = 256 * 1024
# El bundle admin crece con cada slice; su tope de lectura es independiente.
MAX_ASSET_BYTES = 4 * 1024 * 1024


class ObservacionError(Exception):
    """Error presentable sin detalles de red ni datos sensibles."""


class ObservacionTransitoria(ObservacionError):
    """Fallo externo que puede recuperarse sin cambiar el release esperado."""


class ObservacionIdentidad(ObservacionError):
    """La versión o el SHA observados no corresponden al release esperado."""


class ObservacionDeployPendiente(ObservacionIdentidad):
    """Producción aún sirve una versión anterior: el deploy no ha llegado."""


class NoRedirigir(HTTPRedirectHandler):
    def redirect_request(self, request: Request, fp: Any, code: int,
                         msg: str, headers: Any, newurl: str) -> None:
        return None


class TextoVisible(HTMLParser):
    """Extrae texto visible y campos del login, excluyendo scripts y estilos."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.ocultos: list[str] = []
        self.en_head = False
        self.en_body = False
        self.en_hero = False
        self.secciones_hero: list[bool] = []
        self.en_titulo_hero = False
        self.titulos_hero: list[str] = []
        self.slugs_hero: list[str] = []
        self.textos: list[str] = []
        self.en_formulario = 0
        self.campos: set[tuple[str, str]] = set()
        self.canonicals: list[str] = []
        self.storefront_hero = False

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag in {"script", "style", "template", "title"}:
            self.ocultos.append(tag)
            return
        if self.ocultos:
            return

        self._registrar_etiqueta_activa(tag, dict(attrs))

    def _registrar_etiqueta_activa(
        self, tag: str, atributos: dict[str, str | None],
    ) -> None:
        if tag == "head":
            self.en_head = True
        elif tag == "body":
            self.en_head = False
            self.en_body = True
        elif (tag == "link" and self.en_head
              and "canonical" in (atributos.get("rel") or "").split()):
            self.canonicals.append(atributos.get("href") or "")
        elif tag == "section" and self.en_body:
            es_hero = "storefront-hero" in (atributos.get("class") or "").split()
            self.secciones_hero.append(es_hero)
            if es_hero:
                self.storefront_hero = True
                self.en_hero = True
                self.slugs_hero.append(atributos.get("data-tenant-slug") or "")
        elif tag == "h1" and self.en_hero:
            self.en_titulo_hero = True
        elif tag in {"form", "input"}:
            self._registrar_formulario(tag, atributos)

    def _registrar_formulario(
        self, tag: str, atributos: dict[str, str | None],
    ) -> None:
        if not self.en_body:
            return
        if tag == "form":
            self.en_formulario += 1
        elif self.en_formulario:
            self.campos.add((atributos.get("name") or "", atributos.get("type") or "text"))

    def handle_endtag(self, tag: str) -> None:
        if tag in {"script", "style", "template", "title"}:
            self._cerrar_oculto(tag)
            return
        if self.ocultos:
            return

        self._cerrar_etiqueta_activa(tag)

    def _cerrar_oculto(self, tag: str) -> None:
        if self.ocultos and self.ocultos[-1] == tag:
            self.ocultos.pop()

    def _cerrar_etiqueta_activa(self, tag: str) -> None:
        if tag == "head":
            self.en_head = False
        elif tag == "body":
            self.en_body = False
        elif tag == "h1":
            self.en_titulo_hero = False
        elif tag == "section":
            self._cerrar_seccion()
        elif tag == "form":
            self.en_formulario = max(0, self.en_formulario - 1)

    def _cerrar_seccion(self) -> None:
        if self.secciones_hero:
            self.secciones_hero.pop()
        self.en_hero = any(self.secciones_hero)

    def handle_data(self, data: str) -> None:
        if self.en_body and not self.ocultos:
            self.textos.append(data)
            if self.en_titulo_hero:
                self.titulos_hero.append(data)


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


def clasificar_error_http(
    error: HTTPError, estado_esperado: int,
) -> tuple[str, bytes]:
    """Permite el 404 esperado sin ocultar fallos del servidor ni redirecciones."""
    if error.code == estado_esperado == 404:
        return error.headers.get_content_type(), b""
    if error.code in {408, 425, 429} or 500 <= error.code < 600:
        raise ObservacionTransitoria(
            f"HTTP {error.code}; fallo transitorio al observar producción."
        ) from error
    if 300 <= error.code < 400:
        raise ObservacionError(
            f"Redirección HTTP {error.code} no permitida."
        ) from error
    raise ObservacionError(
        f"HTTP {error.code}; se esperaba {estado_esperado}."
    ) from error


def tipo_aceptado_para(ruta: str) -> str:
    """Devuelve el Accept mínimo esperado por el tipo de recurso."""
    if ruta == "/health":
        return "application/json"
    if ruta.endswith(".css"):
        return "text/css"
    if ruta.endswith(".js"):
        return "text/javascript, application/javascript, application/x-javascript"
    return "text/html"


def limite_respuesta_para(ruta: str) -> int:
    """Separa el presupuesto de assets del de HTML/JSON."""
    return MAX_ASSET_BYTES if ruta.endswith((".css", ".js")) else MAX_BYTES


def elevar_error_url(error: URLError) -> None:
    """Clasifica errores de urllib sin filtrar detalles sensibles."""
    reason = error.reason
    if isinstance(
        reason,
        (
            TimeoutError,
            ConnectionAbortedError,
            ConnectionRefusedError,
            ConnectionResetError,
        ),
    ):
        raise ObservacionTransitoria(
            "La conexión falló temporalmente durante la observación."
        ) from error
    if isinstance(reason, ssl.SSLError):
        raise ObservacionError(
            "La conexión TLS no pudo validarse de forma segura."
        ) from error
    if isinstance(reason, socket.gaierror):
        if reason.errno == socket.EAI_AGAIN:
            raise ObservacionTransitoria(
                "La resolución DNS falló temporalmente durante la observación."
            ) from error
        raise ObservacionError(
            "El dominio de producción no pudo resolverse de forma válida."
        ) from error
    raise ObservacionError(
        "No se recibió una respuesta HTTP válida."
    ) from error


def obtener(
    origen: str, ruta: str, timeout: float, *, estado_esperado: int = 200,
) -> tuple[str, bytes]:
    """GET sin redirecciones, cookies ni credenciales, con cuerpo limitado."""
    solicitud = Request(
        origen + ruta,
        headers={
            "Accept": tipo_aceptado_para(ruta),
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
            "User-Agent": "Condor-Release-Observer/0.1",
        },
        method="GET",
    )
    try:
        with build_opener(NoRedirigir).open(solicitud, timeout=timeout) as respuesta:
            if respuesta.status != estado_esperado:
                raise ObservacionError(
                    f"HTTP {respuesta.status}; se esperaba {estado_esperado}."
                )
            limite = limite_respuesta_para(ruta)
            contenido = respuesta.read(limite + 1)
            if len(contenido) > limite:
                raise ObservacionError("La respuesta supera el límite permitido.")
            return respuesta.headers.get_content_type(), contenido
    except HTTPError as error:
        return clasificar_error_http(error, estado_esperado)
    except URLError as error:
        elevar_error_url(error)
        raise AssertionError("elevar_error_url siempre lanza una excepción")
    except (
        TimeoutError,
        ConnectionAbortedError,
        ConnectionRefusedError,
        ConnectionResetError,
    ) as error:
        raise ObservacionTransitoria(
            "La conexión falló temporalmente durante la observación."
        ) from error
    except OSError as error:
        raise ObservacionError(
            "La solicitud HTTP falló de forma no reintentable."
        ) from error


def obtener_estado_protegido(origen: str, ruta: str, timeout: float) -> int:
    """Comprueba una superficie protegida sin autenticar ni seguir redirecciones."""
    solicitud = Request(
        origen + ruta,
        headers={
            "Accept": "text/html",
            "Cache-Control": "no-cache",
            "Pragma": "no-cache",
            "User-Agent": "Condor-Release-Observer/0.1",
        },
        method="GET",
    )
    try:
        with build_opener(NoRedirigir).open(solicitud, timeout=timeout) as respuesta:
            if respuesta.status != 200:
                raise ObservacionError(
                    f"HTTP {respuesta.status}; respuesta inesperada en superficie protegida."
                )
            return respuesta.status
    except HTTPError as error:
        if error.code in {302, 303, 307, 308, 401, 403}:
            return error.code
        if error.code in {408, 425, 429} or 500 <= error.code < 600:
            raise ObservacionTransitoria(
                f"HTTP {error.code}; fallo transitorio al observar superficie protegida."
            ) from error
        raise ObservacionError(
            f"HTTP {error.code}; respuesta inesperada en superficie protegida."
        ) from error
    except URLError as error:
        elevar_error_url(error)
        raise AssertionError("elevar_error_url siempre lanza una excepción")
    except (
        TimeoutError,
        ConnectionAbortedError,
        ConnectionRefusedError,
        ConnectionResetError,
    ) as error:
        raise ObservacionTransitoria(
            "La conexión falló temporalmente durante la observación."
        ) from error
    except OSError as error:
        raise ObservacionError(
            "La solicitud HTTP falló de forma no reintentable."
        ) from error


def validar_health(tipo: str, cuerpo: bytes, version: str, sha: str) -> bool:
    if tipo != "application/json":
        raise ObservacionError("/health no respondió con JSON.")
    try:
        carga = json.loads(cuerpo.decode("utf-8"))
    except ValueError as error:
        raise ObservacionError("/health devolvió JSON inválido.") from error
    if not isinstance(carga, dict) or carga.get("status") != "ok":
        raise ObservacionError("/health no informa estado ok.")
    observada = carga.get("version")
    observada_sha = carga.get("release_sha")
    if not isinstance(observada_sha, str) or SHA_PATTERN.fullmatch(observada_sha) is None:
        raise ObservacionIdentidad(
            "El SHA observado no tiene un formato válido."
        )
    if (isinstance(observada, str) and VERSION_PATTERN.fullmatch(observada)
            and tuple(map(int, observada.split("."))) < tuple(map(int, version.split(".")))):
        raise ObservacionDeployPendiente(
            f"Producción aún sirve V {observada}; el deploy esperado no ha llegado."
        )
    if observada != version:
        raise ObservacionIdentidad(
            "La versión observada no coincide con la esperada."
        )
    if observada_sha != sha:
        raise ObservacionIdentidad(
            "El SHA observado no coincide con el esperado."
        )
    return carga.get("schema_up_to_date") is True


def validar_pagina(tipo: str, cuerpo: bytes, version: str, login: bool) -> TextoVisible:
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
    return analizador


def validar_tenant_slug(slug: str) -> str:
    if (len(slug) > 120 or TENANT_SLUG_PATTERN.fullmatch(slug) is None
            or slug in {"admin", "health", "api"}):
        raise ObservacionError("El slug de storefront debe ser un segmento público válido.")
    return slug


def validar_canonical(valor: str, origen: str, slug: str) -> str:
    url = urlsplit(valor)
    try:
        puerto = url.port
    except ValueError as error:
        raise ObservacionError("Puerto inválido en el canonical esperado.") from error
    if (url.scheme != "https" or not url.hostname or url.username is not None
            or url.password is not None or puerto is not None or url.query
            or url.fragment or url.path not in {"/", "/" + slug}):
        raise ObservacionError("El canonical esperado debe ser HTTPS y apuntar al storefront.")
    if url.hostname == urlsplit(origen).hostname and url.path != "/" + slug:
        raise ObservacionError("El canonical de Condor debe incluir el slug del tenant.")
    return valor


def validar_storefront(
    tipo: str, cuerpo: bytes, version: str, canonical: str, slug: str,
) -> None:
    pagina = validar_pagina(tipo, cuerpo, version, login=False)
    if not pagina.storefront_hero:
        raise ObservacionError("El storefront no contiene la sección pública esperada.")
    if not "".join(pagina.titulos_hero).strip():
        raise ObservacionError("El storefront no contiene un título público visible.")
    if pagina.canonicals != [canonical]:
        raise ObservacionError("El canonical del storefront no coincide con el esperado.")
    if (tuple(map(int, version.split("."))) >= STOREFRONT_IDENTITY_VERSION
            and pagina.slugs_hero != [slug]):
        raise ObservacionError("La identidad del tenant del storefront no coincide con el slug esperado.")


def validar_asset(tipo: str, cuerpo: bytes, ruta: str) -> None:
    """Comprueba que el asset servido no sea una página fallback, vacío ni MIME erróneo."""
    esperados = (
        {"text/css"} if ruta.endswith(".css")
        else {"text/javascript", "application/javascript", "application/x-javascript"}
    )
    if tipo not in esperados:
        raise ObservacionError("El recurso estático no tiene el tipo de contenido esperado.")
    if not cuerpo.strip():
        raise ObservacionError("El recurso estático está vacío.")


def ejecutar_con_reintentos(
    operacion: Callable[[], str],
    *,
    intentos: int,
    intervalo: float,
) -> tuple[bool, str, int, str]:
    """Reintenta solo fallos externos y clasifica la evidencia resultante."""
    for intento in range(1, intentos + 1):
        try:
            return True, operacion(), intento, "ok"
        except ObservacionTransitoria as error:
            if intento == intentos:
                return False, str(error), intento, "transitorio"
            time.sleep(intervalo)
        except ObservacionDeployPendiente as error:
            return False, str(error), intento, "deploy_pendiente"
        except ObservacionIdentidad as error:
            return False, str(error), intento, "identidad"
        except ObservacionError as error:
            return False, str(error), intento, "funcional"

    return False, "La comprobación no produjo resultado.", intentos, "funcional"


def preparar_canonical(
    origen: str, tenant_slug: str | None, canonical: str | None,
) -> str | None:
    if canonical is not None and tenant_slug is None:
        raise ObservacionError("El canonical requiere un slug de storefront.")
    if tenant_slug is None:
        return None
    validar_tenant_slug(tenant_slug)
    if canonical is not None:
        return validar_canonical(canonical, origen, tenant_slug)
    return origen + "/" + tenant_slug


def observar_storefront(
    origen: str, version: str, sha: str, *, tenant_slug: str | None,
    canonical: str | None, intentos: int, intervalo: float, timeout: float,
) -> dict[str, dict[str, Any]]:
    evidencias: dict[str, dict[str, Any]] = {}
    if tenant_slug is None:
        evidencias["storefront"] = {
            "ok": False, "clase": "funcional",
            "detalle": "No se proporcionó un tenant para comprobar el storefront.",
        }
    else:
        def comprobar_storefront() -> str:
            tipo, cuerpo = obtener(origen, "/" + tenant_slug, timeout)
            validar_storefront(tipo, cuerpo, version, canonical or "", tenant_slug)
            return "SSR, versión, identidad tenant y canonical exactos confirmados."

        ok, detalle, intento, clase = ejecutar_con_reintentos(
            comprobar_storefront, intentos=intentos, intervalo=intervalo,
        )
        evidencias["storefront"] = {
            "ok": ok, "detalle": detalle, "intento": intento, "clase": clase,
        }

    desconocido = "/condor-smoke-no-existe-" + sha[:12]

    def comprobar_slug_desconocido() -> str:
        obtener(origen, desconocido, timeout, estado_esperado=404)
        return "Slug inexistente rechazado con HTTP 404, sin redirección."

    ok, detalle, intento, clase = ejecutar_con_reintentos(
        comprobar_slug_desconocido, intentos=intentos, intervalo=intervalo,
    )
    evidencias["slug_desconocido"] = {
        "ok": ok, "detalle": detalle, "intento": intento, "clase": clase,
    }
    return evidencias


def observar_health(
    origen: str,
    version: str,
    sha: str,
    *,
    intentos: int,
    intervalo: float,
    timeout: float,
    espera_deploy: float,
    intervalo_deploy: float,
) -> tuple[dict[str, Any], bool]:
    """Espera solo despliegues atrasados y devuelve evidencia de identidad/esquema."""
    schema_up_to_date = False

    def comprobar_health() -> str:
        nonlocal schema_up_to_date
        tipo, cuerpo = obtener(origen, "/health", timeout)
        schema_up_to_date = validar_health(tipo, cuerpo, version, sha)
        return "Versión y SHA exactos confirmados."

    limite_espera = time.monotonic() + espera_deploy
    deploy_pendiente_observado = False
    while True:
        ok, detalle, intento, clase = ejecutar_con_reintentos(
            comprobar_health,
            intentos=intentos,
            intervalo=intervalo,
        )
        if clase == "deploy_pendiente":
            deploy_pendiente_observado = True
        elif not (
            deploy_pendiente_observado
            and clase == "transitorio"
        ):
            break

        tiempo_restante = limite_espera - time.monotonic()
        if tiempo_restante <= 0:
            break

        time.sleep(min(intervalo_deploy, tiempo_restante))
        if time.monotonic() >= limite_espera:
            break

    return {
        "ok": ok,
        "detalle": detalle,
        "intento": intento,
        "clase": clase,
    }, schema_up_to_date


def observar(
    origen: str,
    version: str,
    sha: str,
    *,
    intentos: int = 3,
    intervalo: float = 2,
    timeout: float = 5,
    transicion_requerida: bool = False,
    transicion_verificada: bool = False,
    tenant_slug: str | None = None,
    canonical_storefront: str | None = None,
    espera_deploy: float = 0,
    intervalo_deploy: float = 30,
) -> dict[str, Any]:
    """Solo la identidad exacta permite pasar de NO_OBSERVADO a DEPLOY_OBSERVED."""
    evidencias: dict[str, dict[str, Any]] = {}
    canonical_storefront = preparar_canonical(
        origen, tenant_slug, canonical_storefront,
    )

    health, schema_up_to_date = observar_health(
        origen,
        version,
        sha,
        intentos=intentos,
        intervalo=intervalo,
        timeout=timeout,
        espera_deploy=espera_deploy,
        intervalo_deploy=intervalo_deploy,
    )
    evidencias["health"] = health
    if not health["ok"]:
        return {
            "estado": "NO_OBSERVADO",
            "version_esperada": version,
            "sha_esperado": sha,
            "comprobaciones": evidencias,
        }

    evidencias["schema"] = {
        "ok": schema_up_to_date,
        "clase": "ok" if schema_up_to_date else "funcional",
        "detalle": (
            "El esquema de producción coincide con las migraciones del release."
            if schema_up_to_date
            else "El health no confirma que el esquema de producción esté al día."
        ),
    }

    for nombre, ruta, es_login in [
        ("home", "/", False),
        ("admin_login", "/admin/login", True),
    ]:
        def comprobar_pagina(
            ruta_actual: str = ruta,
            login_actual: bool = es_login,
        ) -> str:
            tipo, cuerpo = obtener(origen, ruta_actual, timeout)
            validar_pagina(tipo, cuerpo, version, login_actual)
            return "HTTP 200, HTML y versión visibles."

        ok, detalle, intento, clase = ejecutar_con_reintentos(
            comprobar_pagina,
            intentos=intentos,
            intervalo=intervalo,
        )
        evidencias[nombre] = {
            "ok": ok,
            "detalle": detalle,
            "intento": intento,
            "clase": clase,
        }

    def comprobar_centro_control() -> str:
        codigo = obtener_estado_protegido(origen, "/adminpl0n3r", timeout)
        return (
            f"HTTP {codigo}; entrada protegida del centro de control responde sin 5xx."
        )

    ok, detalle, intento, clase = ejecutar_con_reintentos(
        comprobar_centro_control,
        intentos=intentos,
        intervalo=intervalo,
    )
    evidencias["centro_control"] = {
        "ok": ok,
        "detalle": detalle,
        "intento": intento,
        "clase": clase,
    }

    for nombre, ruta in [
        ("css_publico", "/app.css"),
        ("css_admin", "/build/admin.css"),
        ("js_admin", "/build/admin.js"),
    ]:
        def comprobar_asset(ruta_actual: str = ruta) -> str:
            tipo, cuerpo = obtener(origen, ruta_actual, timeout)
            validar_asset(tipo, cuerpo, ruta_actual)
            return "HTTP 200 y contenido estático válido."

        ok, detalle, intento, clase = ejecutar_con_reintentos(
            comprobar_asset,
            intentos=intentos,
            intervalo=intervalo,
        )
        evidencias[nombre] = {
            "ok": ok,
            "detalle": detalle,
            "intento": intento,
            "clase": clase,
        }

    if tuple(map(int, version.split("."))) >= STOREFRONT_VERSION:
        evidencias.update(observar_storefront(
            origen, version, sha, tenant_slug=tenant_slug,
            canonical=canonical_storefront, intentos=intentos,
            intervalo=intervalo, timeout=timeout,
        ))

    if transicion_requerida:
        evidencias["transicion_release"] = {
            "ok": transicion_verificada,
            "clase": "ok" if transicion_verificada else "funcional",
            "detalle": (
                "Transición operativa verificada de forma independiente."
                if transicion_verificada
                else "La release requiere transición de esquema/configuración/comandos "
                "y todavía no existe verificación operativa."
            ),
        }

    valido = all(item["ok"] for item in evidencias.values())
    return {
        "estado": "VALIDATED_IN_PRODUCTION" if valido else "DEPLOY_OBSERVED",
        "version_esperada": version,
        "sha_esperado": sha,
        "comprobaciones": evidencias,
    }
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


def comentario_roadmap(resultado: dict[str, Any]) -> str:
    """Comentario honesto para #1: nombra la causa real en vez de suponerla."""
    identidad = f"V {resultado['version_esperada']} ({resultado['sha_esperado']})"
    fallos = [
        f"`{nombre}`: {item['detalle']}"
        for nombre, item in resultado["comprobaciones"].items() if not item["ok"]
    ]
    if resultado["estado"] == "VALIDATED_IN_PRODUCTION":
        return (f"✅ VALIDATED_IN_PRODUCTION automático: producción sirve {identidad} "
                "y los smoke checks de solo lectura pasaron.")
    if resultado["estado"] == "DEPLOY_OBSERVED":
        return (f"🚧 DEPLOY_OBSERVED automático: producción sirve {identidad}, "
                "pero no se declara validada. Pendiente:\n- " + "\n- ".join(fallos))
    health = resultado["comprobaciones"]["health"]
    if health.get("clase") == "deploy_pendiente":
        return (f"⏳ NO_OBSERVADO: tras la espera acotada, producción todavía no sirve {identidad}. "
                f"{health['detalle']} Revisar el deploy de Hostinger si persiste.")
    if health.get("clase") == "identidad":
        return (f"⛔ NO_OBSERVADO: la identidad de producción no coincide con {identidad}. "
                f"{health['detalle']}")
    return (f"⛔ NO_OBSERVADO: no fue posible confirmar {identidad} en producción. "
            f"{health['detalle']}")


def crear_parser() -> argparse.ArgumentParser:
    """Construye el contrato CLI sin mezclarlo con la ejecución."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True, help="Versión esperada, p. ej. 0.1.0")
    parser.add_argument("--sha", required=True, help="SHA exacto de 40 caracteres del merge")
    parser.add_argument("--intentos", type=int, default=3)
    parser.add_argument("--intervalo", type=float, default=2)
    parser.add_argument("--timeout", type=float, default=5)
    parser.add_argument("--espera-deploy", type=float, default=0,
                        help="Segundos máximos esperando que llegue el deploy (0-1800)")
    parser.add_argument("--intervalo-deploy", type=float, default=30)
    parser.add_argument("--markdown", action="store_true", help="Salida para el Job Summary")
    parser.add_argument("--tenant-slug", help="Slug real conocido para smoke público del storefront")
    parser.add_argument("--canonical-storefront", help="Canonical HTTPS esperado para ese tenant")
    parser.add_argument(
        "--transicion-requerida",
        action="store_true",
        help="Impide validar producción hasta confirmar la transición operativa.",
    )
    parser.add_argument(
        "--transicion-verificada",
        action="store_true",
        help="Confirma que la transición requerida fue comprobada fuera del deploy de código.",
    )
    return parser


def validar_identidad_cli(args: argparse.Namespace) -> None:
    if not VERSION_PATTERN.fullmatch(args.version):
        raise ObservacionError("La versión debe tener formato X.Y.Z.")
    if not SHA_PATTERN.fullmatch(args.sha):
        raise ObservacionError(
            "El SHA esperado debe tener exactamente 40 caracteres hexadecimales minúsculos."
        )


def validar_storefront_cli(args: argparse.Namespace, origen: str) -> None:
    if args.canonical_storefront and not args.tenant_slug:
        raise ObservacionError("El canonical requiere --tenant-slug.")
    if args.tenant_slug:
        validar_tenant_slug(args.tenant_slug)
    if args.tenant_slug and args.canonical_storefront:
        validar_canonical(args.canonical_storefront, origen, args.tenant_slug)


def validar_presupuestos_cli(args: argparse.Namespace) -> None:
    if not 1 <= args.intentos <= 10:
        raise ObservacionError("Intentos debe estar entre 1 y 10.")
    if not 0 <= args.intervalo <= 60:
        raise ObservacionError("Intervalo debe estar entre 0 y 60 segundos.")
    if not 0.1 <= args.timeout <= 30:
        raise ObservacionError("Timeout debe estar entre 0.1 y 30 segundos.")
    if not 0 <= args.espera_deploy <= 1800:
        raise ObservacionError("Espera de deploy debe estar entre 0 y 1800 segundos.")
    if not 5 <= args.intervalo_deploy <= 120:
        raise ObservacionError("Intervalo de deploy debe estar entre 5 y 120 segundos.")


def validar_transicion_cli(args: argparse.Namespace) -> None:
    if args.transicion_verificada and not args.transicion_requerida:
        raise ObservacionError(
            "--transicion-verificada solo es válida junto con --transicion-requerida."
        )


def main(argv: list[str] | None = None) -> int:
    parser = crear_parser()
    args = parser.parse_args(argv)
    origen = DOMINIO_PRODUCCION

    try:
        validar_identidad_cli(args)
        validar_storefront_cli(args, origen)
        validar_presupuestos_cli(args)
        validar_transicion_cli(args)
    except ObservacionError as error:
        parser.error(str(error))

    resultado = observar(
        origen,
        args.version,
        args.sha,
        intentos=args.intentos,
        intervalo=args.intervalo,
        timeout=args.timeout,
        transicion_requerida=args.transicion_requerida,
        transicion_verificada=args.transicion_verificada,
        tenant_slug=args.tenant_slug,
        canonical_storefront=args.canonical_storefront,
        espera_deploy=args.espera_deploy,
        intervalo_deploy=args.intervalo_deploy,
    )
    reporte = json.dumps(resultado, ensure_ascii=False, indent=2) + "\n"
    print(resumen(resultado) if args.markdown else reporte, end="")
    return 0 if resultado["estado"] == "VALIDATED_IN_PRODUCTION" else 1


if __name__ == "__main__":
    sys.exit(main())
