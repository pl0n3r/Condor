"""Pruebas de comportamiento del observador sobre HTTP loopback efímero."""

from __future__ import annotations

import importlib.util
import json
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "observar_release.py"
spec = importlib.util.spec_from_file_location("observar_release", SCRIPT)
assert spec is not None
assert spec.loader is not None
modulo = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = modulo
spec.loader.exec_module(modulo)

SHA = "a" * 40
VERSION = "0.1.0"
HOME = b"<html><body><footer>V 0.1.0</footer></body></html>"
LOGIN = (b"<html><body><h1>Ingresar a Condor</h1><form>"
         b"<input name='_username' type='email'>"
         b"<input name='_password' type='password'>"
         b"</form><p>V 0.1.0</p></body></html>")


class SitioFalso(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        self.server.visitas.append(self.path)
        turno = self.server.respuestas.get(self.path, (404, "text/plain", b"No existe"))
        if callable(turno):
            turno = turno()
        codigo, tipo, contenido = turno
        self.send_response(codigo)
        self.send_header("Content-Type", tipo)
        if codigo in (301, 302, 307, 308):
            self.send_header("Location", "https://otro-ejemplo.invalid/")
        self.end_headers()
        try:
            self.wfile.write(contenido)
        except (BrokenPipeError, ConnectionResetError):
            pass

    def log_message(self, *args: object) -> None:
        pass


class ObserverTests(unittest.TestCase):
    def setUp(self) -> None:
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), SitioFalso)
        self.server.visitas = []
        self.server.respuestas = {
            "/health": (200, "application/json; charset=utf-8", json.dumps({
                "status": "ok", "version": VERSION, "release_sha": SHA,
            }).encode()),
            "/": (200, "text/html", HOME),
            "/admin/login": (200, "text/html", LOGIN),
            "/app.css": (200, "text/css", b"body{margin:0}"),
            "/build/admin.css": (200, "text/css", b".admin{display:grid}"),
            "/build/admin.js": (200, "application/javascript; charset=utf-8", b"window.condor=true;"),
        }
        self.hilo = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.hilo.start()
        self.base = f"http://127.0.0.1:{self.server.server_port}"

    def tearDown(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.hilo.join(timeout=3)

    def observar(self) -> dict:
        return modulo.observar(self.base, VERSION, SHA, intentos=2, intervalo=0, timeout=1)

    def test_release_exacta_y_smoke_publico(self) -> None:
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertEqual(self.server.visitas, [
            "/health", "/", "/admin/login",
            "/app.css", "/build/admin.css", "/build/admin.js",
        ])
        self.assertTrue(all(v["ok"] for v in resultado["comprobaciones"].values()))

    def preparar_storefront(self, *, canonical: str | None = None) -> str:
        """Simula V 0.1.13, un tenant real y el slug fallando cerrado."""
        version = "0.1.13"
        slug = "empresa-prueba"
        self.server.respuestas["/health"] = (
            200, "application/json", json.dumps({
                "status": "ok", "version": version, "release_sha": SHA,
            }).encode(),
        )
        self.server.respuestas["/"] = (
            200, "text/html", HOME.replace(b"0.1.0", b"0.1.13"),
        )
        self.server.respuestas["/admin/login"] = (
            200, "text/html", LOGIN.replace(b"0.1.0", b"0.1.13"),
        )
        url = canonical or self.base + "/" + slug
        html = (
            '<html><head><link rel="canonical" href="' + url
            + '"></head><body><section class="hero storefront-hero">'
            + '<h1>Empresa prueba</h1></section><footer>V 0.1.13</footer></body></html>'
        )
        self.server.respuestas["/" + slug] = (
            200, "text/html", html.encode(),
        )
        self.server.respuestas["/condor-smoke-no-existe-" + SHA[:12]] = (
            404, "text/html", b"No existe",
        )
        return slug

    def test_storefront_real_y_slug_desconocido_validan_release(self) -> None:
        slug = self.preparar_storefront()
        resultado = modulo.observar(
            self.base, "0.1.13", SHA, intentos=1, intervalo=0,
            tenant_slug=slug,
        )
        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertTrue(resultado["comprobaciones"]["storefront"]["ok"])
        self.assertTrue(resultado["comprobaciones"]["slug_desconocido"]["ok"])
        self.assertIn("/" + slug, self.server.visitas)

    def test_storefront_sin_slug_no_declara_produccion_validada(self) -> None:
        self.preparar_storefront()
        resultado = modulo.observar(
            self.base, "0.1.13", SHA, intentos=1, intervalo=0,
        )
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["storefront"]["ok"])
        self.assertTrue(resultado["comprobaciones"]["slug_desconocido"]["ok"])

    def test_canonical_equivocado_no_valida_storefront(self) -> None:
        slug = self.preparar_storefront()
        resultado = modulo.observar(
            self.base, "0.1.13", SHA, intentos=1, intervalo=0,
            tenant_slug=slug,
            canonical_storefront="https://otra-empresa.example/",
        )
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["storefront"]["ok"])
        self.assertIn("canonical", resultado["comprobaciones"]["storefront"]["detalle"])

    def test_canonical_personalizado_correcto_es_aceptado(self) -> None:
        canonical = "https://empresa.example/"
        slug = self.preparar_storefront(canonical=canonical)
        resultado = modulo.observar(
            self.base, "0.1.13", SHA, intentos=1, intervalo=0,
            tenant_slug=slug, canonical_storefront=canonical,
        )
        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")

    def test_fallback_html_sin_storefront_real_es_rechazado(self) -> None:
        slug = self.preparar_storefront()
        self.server.respuestas["/" + slug] = (
            200, "text/html", HOME.replace(b"0.1.0", b"0.1.13"),
        )
        resultado = modulo.observar(
            self.base, "0.1.13", SHA, intentos=1, intervalo=0,
            tenant_slug=slug,
        )
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertIn("sección pública", resultado["comprobaciones"]["storefront"]["detalle"])

    def test_marcadores_inertes_dentro_de_template_no_validan_storefront(self) -> None:
        slug = self.preparar_storefront()
        canonical = self.base + "/" + slug
        casos = {
            "ambos_en_template": (
                '<html><head><template><link rel="canonical" href="' + canonical
                + '"></template></head><body><template>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '</template><footer>V 0.1.13</footer></body></html>'
            ),
            "solo_hero_en_template": (
                '<html><head><link rel="canonical" href="' + canonical
                + '"></head><body><template>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '</template><footer>V 0.1.13</footer></body></html>'
            ),
            "solo_canonical_en_template": (
                '<html><head><template><link rel="canonical" href="' + canonical
                + '"></template></head><body>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '<footer>V 0.1.13</footer></body></html>'
            ),
            "canonical_fuera_de_head": (
                '<html><head></head><body><link rel="canonical" href="' + canonical
                + '"><section class="storefront-hero"><h1>Empresa</h1></section>'
                + '<footer>V 0.1.13</footer></body></html>'
            ),
            "cierre_no_correspondiente_en_template": (
                '<html><head><template></style><link rel="canonical" href="' 
                + canonical + '"></template></head><body><template></script>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '</template><footer>V 0.1.13</footer></body></html>'
            ),
            "cierre_no_correspondiente_con_anidamiento": (
                '<html><head><template><template></style>'
                + '<link rel="canonical" href="' + canonical
                + '"></template></template></head><body><template></style>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '</template><footer>V 0.1.13</footer></body></html>'
            ),
            "template_anidado": (
                '<html><head><template><template><link rel="canonical" href="'
                + canonical + '"></template></template></head><body><template>'
                + '<section class="storefront-hero"><h1>Empresa</h1></section>'
                + '</template><footer>V 0.1.13</footer></body></html>'
            ),
        }
        for nombre, pagina in casos.items():
            with self.subTest(nombre=nombre):
                self.server.respuestas["/" + slug] = (
                    200, "text/html", pagina.encode(),
                )
                resultado = modulo.observar(
                    self.base, "0.1.13", SHA, intentos=1, intervalo=0,
                    tenant_slug=slug,
                )
                self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
                self.assertFalse(resultado["comprobaciones"]["storefront"]["ok"])

    def test_login_ignora_campos_tras_cierre_no_correspondiente(self) -> None:
        pagina = (
            '<html><body><template></style><form>'
            '<input name="_username" type="email">'
            '<input name="_password" type="password">'
            '</form></template><footer>V 0.1.0</footer></body></html>'
        )
        self.server.respuestas["/admin/login"] = (200, "text/html", pagina.encode())
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["admin_login"]["ok"])

    def test_formulario_inerte_en_template_no_valida_login(self) -> None:
        pagina = (
            '<html><body><template><form>'
            '<input name="_username" type="email">'
            '<input name="_password" type="password">'
            '</form></template><footer>V 0.1.0</footer></body></html>'
        )
        self.server.respuestas["/admin/login"] = (200, "text/html", pagina.encode())
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["admin_login"]["ok"])

    def test_slug_desconocido_200_o_redirect_no_se_acepta(self) -> None:
        slug = self.preparar_storefront()
        desconocido = "/condor-smoke-no-existe-" + SHA[:12]
        for estado in (200, 302):
            with self.subTest(estado=estado):
                self.server.respuestas[desconocido] = (
                    estado, "text/html", b"<!doctype html>",
                )
                self.server.visitas.clear()
                with patch.object(modulo.time, "sleep") as sleep:
                    resultado = modulo.observar(
                        self.base, "0.1.13", SHA, intentos=3,
                        intervalo=0, tenant_slug=slug,
                    )
                self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
                self.assertFalse(resultado["comprobaciones"]["slug_desconocido"]["ok"])
                self.assertEqual(self.server.visitas.count(desconocido), 1)
                sleep.assert_not_called()

    def test_slug_y_canonical_de_entrada_no_permiten_rutas_arbitrarias(self) -> None:
        for slug in ("", "empresa/otra", "../admin", "admin", "EMPRESA", "a" * 121):
            with self.subTest(slug=slug):
                with self.assertRaises(modulo.ObservacionError):
                    modulo.validar_tenant_slug(slug)
        for canonical in (
            "http://empresa.example/", "https://user:pass@empresa.example/",
            "https://empresa.example/otra", "https://empresa.example/?token=x",
            "https://empresa.example/#fragment", "https://www.condorapp.com.co/",
        ):
            with self.subTest(canonical=canonical):
                with self.assertRaises(modulo.ObservacionError):
                    modulo.validar_canonical(
                        canonical, modulo.DOMINIO_PRODUCCION, "empresa-prueba",
                    )

    def test_transition_required_without_verification_blocks_validation(self) -> None:
        resultado = modulo.observar(
            self.base,
            VERSION,
            SHA,
            intentos=1,
            transicion_requerida=True,
        )
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["transicion_release"]["ok"])

    def test_verified_transition_allows_validation(self) -> None:
        resultado = modulo.observar(
            self.base,
            VERSION,
            SHA,
            intentos=1,
            transicion_requerida=True,
            transicion_verificada=True,
        )
        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertTrue(resultado["comprobaciones"]["transicion_release"]["ok"])

    def test_faltan_assets_no_declara_produccion_valida(self) -> None:
        del self.server.respuestas["/build/admin.js"]
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["js_admin"]["ok"])

    def test_html_fallback_no_pasa_por_javascript(self) -> None:
        self.server.respuestas["/build/admin.js"] = (200, "text/html", HOME)
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["js_admin"]["ok"])

    def test_css_vacio_no_valida_produccion(self) -> None:
        self.server.respuestas["/app.css"] = (200, "text/css", b"  ")
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertIn("vacío", resultado["comprobaciones"]["css_publico"]["detalle"])

    def test_css_mime_erroneo_no_valida_produccion(self) -> None:
        self.server.respuestas["/build/admin.css"] = (200, "application/octet-stream", b"css")
        self.assertEqual(self.observar()["estado"], "DEPLOY_OBSERVED")

    def test_asset_redirigido_no_valida_produccion(self) -> None:
        self.server.respuestas["/build/admin.js"] = (302, "application/javascript", b"")
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertIn("Redirección", resultado["comprobaciones"]["js_admin"]["detalle"])

    def test_asset_demasiado_grande_no_valida_produccion(self) -> None:
        self.server.respuestas["/build/admin.js"] = (
            200, "application/javascript", b"a" * (modulo.MAX_BYTES + 1),
        )
        self.assertEqual(self.observar()["estado"], "DEPLOY_OBSERVED")

    def test_sha_distinto_no_se_reintenta_ni_visita_paginas(self) -> None:
        with patch.object(modulo.time, "sleep") as sleep:
            resultado = modulo.observar(
                self.base,
                VERSION,
                "b" * 40,
                intentos=3,
                intervalo=1,
            )
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")
        self.assertEqual(self.server.visitas, ["/health"])
        self.assertEqual(resultado["comprobaciones"]["health"]["intento"], 1)
        sleep.assert_not_called()

    def test_version_distinta_no_observa_deploy(self) -> None:
        resultado = modulo.observar(self.base, "0.1.1", SHA, intentos=1)
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")
        self.assertEqual(resultado["comprobaciones"]["health"]["clase"], "funcional")

    def test_health_5xx_y_json_invalido_no_observan_deploy(self) -> None:
        for codigo, cuerpo in [(503, b"fallo"), (200, b"{mal json")]:
            with self.subTest(codigo=codigo, cuerpo=cuerpo):
                self.server.respuestas["/health"] = (codigo, "application/json", cuerpo)
                self.assertEqual(self.observar()["estado"], "NO_OBSERVADO")

    def test_health_no_json_no_observa_deploy(self) -> None:
        self.server.respuestas["/health"] = (200, "text/html", HOME)
        self.assertEqual(self.observar()["estado"], "NO_OBSERVADO")

    def test_home_sin_version_solo_deploy_observado(self) -> None:
        self.server.respuestas["/"] = (200, "text/html", b"<html>Hola</html>")
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["home"]["ok"])

    def test_login_sin_formulario_solo_deploy_observado(self) -> None:
        self.server.respuestas["/admin/login"] = (200, "text/html", HOME)
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertFalse(resultado["comprobaciones"]["admin_login"]["ok"])

    def test_login_404_solo_deploy_observado(self) -> None:
        self.server.respuestas["/admin/login"] = (404, "text/html", b"no existe")
        self.assertEqual(self.observar()["estado"], "DEPLOY_OBSERVED")

    def test_no_confunde_version_en_javascript_con_version_visible(self) -> None:
        self.server.respuestas["/"] = (200, "text/html", b"<script>const v = 'V 0.1.0'</script>")
        self.assertEqual(self.observar()["estado"], "DEPLOY_OBSERVED")

    def test_redireccion_se_rechaza_y_no_se_sigue(self) -> None:
        self.server.respuestas["/health"] = (302, "application/json", b"")
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")
        self.assertIn("Redirecci", resultado["comprobaciones"]["health"]["detalle"])

    def test_reintenta_health_transitorio(self) -> None:
        pendientes = [(503, "application/json", b""), self.server.respuestas["/health"]]
        self.server.respuestas["/health"] = lambda: pendientes.pop(0)
        resultado = self.observar()
        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertEqual(resultado["comprobaciones"]["health"]["intento"], 2)

    def test_timeout_transitorio_agota_presupuesto_sin_validar(self) -> None:
        with patch.object(
            modulo,
            "obtener",
            side_effect=modulo.ObservacionTransitoria("Tiempo agotado"),
        ), patch.object(modulo.time, "sleep") as sleep:
            resultado = self.observar()
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")
        self.assertEqual(resultado["comprobaciones"]["health"]["intento"], 2)
        self.assertEqual(resultado["comprobaciones"]["health"]["clase"], "transitorio")
        sleep.assert_called_once_with(0)

    def test_home_transitorio_se_recupera_dentro_del_presupuesto(self) -> None:
        pendientes = [
            (503, "text/html", b"temporal"),
            (200, "text/html", HOME),
        ]
        self.server.respuestas["/"] = lambda: pendientes.pop(0)

        with patch.object(modulo.time, "sleep") as sleep:
            resultado = modulo.observar(
                self.base,
                VERSION,
                SHA,
                intentos=2,
                intervalo=1,
                timeout=1,
            )

        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertEqual(resultado["comprobaciones"]["home"]["intento"], 2)
        self.assertEqual(self.server.visitas.count("/"), 2)
        sleep.assert_called_once_with(1)

    def test_asset_429_se_recupera_sin_ocultar_fallo_funcional(self) -> None:
        pendientes = [
            (429, "text/css", b""),
            (200, "text/css", b"body{margin:0}"),
        ]
        self.server.respuestas["/app.css"] = lambda: pendientes.pop(0)

        resultado = modulo.observar(
            self.base,
            VERSION,
            SHA,
            intentos=2,
            intervalo=0,
            timeout=1,
        )

        self.assertEqual(resultado["estado"], "VALIDATED_IN_PRODUCTION")
        self.assertEqual(resultado["comprobaciones"]["css_publico"]["intento"], 2)

    def test_home_sin_version_es_determinista_y_no_se_reintenta(self) -> None:
        self.server.respuestas["/"] = (
            200,
            "text/html",
            b"<html><body>Sin version</body></html>",
        )

        with patch.object(modulo.time, "sleep") as sleep:
            resultado = modulo.observar(
                self.base,
                VERSION,
                SHA,
                intentos=3,
                intervalo=1,
                timeout=1,
            )

        self.assertEqual(resultado["estado"], "DEPLOY_OBSERVED")
        self.assertEqual(self.server.visitas.count("/"), 1)
        self.assertEqual(resultado["comprobaciones"]["home"]["intento"], 1)
        sleep.assert_not_called()

    def test_tls_y_dns_no_se_clasifican_como_transitorios(self) -> None:
        casos = [
            modulo.URLError(
                modulo.ssl.SSLCertVerificationError(
                    1,
                    "certificate verify failed",
                )
            ),
            modulo.URLError(
                modulo.socket.gaierror(
                    modulo.socket.EAI_NONAME,
                    "Name or service not known",
                )
            ),
        ]

        for fallo in casos:
            with self.subTest(fallo=type(fallo.reason).__name__):
                with patch.object(modulo, "build_opener") as opener:
                    opener.return_value.open.side_effect = fallo
                    with self.assertRaises(modulo.ObservacionError) as caught:
                        modulo.obtener(
                            "https://example.invalid",
                            "/health",
                            1,
                        )
                self.assertNotIsInstance(
                    caught.exception,
                    modulo.ObservacionTransitoria,
                )

    def test_timeout_de_urllib_si_es_transitorio(self) -> None:
        with patch.object(modulo, "build_opener") as opener:
            opener.return_value.open.side_effect = modulo.URLError(
                TimeoutError("timed out")
            )
            with self.assertRaises(modulo.ObservacionTransitoria):
                modulo.obtener(
                    "https://example.invalid",
                    "/health",
                    1,
                )

    def test_url_y_salida_no_exponen_credenciales(self) -> None:
        with self.assertRaises(modulo.ObservacionError):
            modulo.validar_base_url("https://usuario:clave@ejemplo.com")
        with self.assertRaises(modulo.ObservacionError):
            modulo.validar_base_url("http://ejemplo.com", True)
        self.assertEqual(modulo.validar_base_url(self.base, True), self.base)
        salida = json.dumps(self.observar()) + modulo.resumen(self.observar())
        self.assertNotIn("password", salida)


if __name__ == "__main__":
    unittest.main()
