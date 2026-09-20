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

    def test_sha_distinto_no_observa_deploy_ni_visita_paginas(self) -> None:
        resultado = modulo.observar(self.base, VERSION, "b" * 40, intentos=1)
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")
        self.assertEqual(self.server.visitas, ["/health"])

    def test_version_distinta_no_observa_deploy(self) -> None:
        resultado = modulo.observar(self.base, "0.1.1", SHA, intentos=1)
        self.assertEqual(resultado["estado"], "NO_OBSERVADO")

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

    def test_timeout_no_se_declara_validado(self) -> None:
        with patch.object(modulo, "obtener", side_effect=modulo.ObservacionError("Tiempo agotado")):
            self.assertEqual(self.observar()["estado"], "NO_OBSERVADO")

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
