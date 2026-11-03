"""Condor #1440: página pública de derechos, configuración y fallos seguros."""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PrivacyRightsController.php"
PAGE = ROOT / "templates/privacidad/derechos.html.twig"
BASE = ROOT / "templates/base.html.twig"
CONFIG = ROOT / "datos.yml"
PLACEHOLDER = "[COMPLETAR POR EL DUEÑO]"

# Se ejecuta el lector real de PHP. La única simulación es el controller base
# de Symfony, que no participa en la lectura/validación de configuración.
PHP_RUNNER = r"""
namespace Symfony\Bundle\FrameworkBundle\Controller {
    abstract class AbstractController {}
}
namespace {
    require $argv[1];
    echo json_encode(
        \App\Http\Controller\PrivacyRightsController::publicControllerInfo($argv[2]),
        JSON_THROW_ON_ERROR
    );
}
"""


class PrivacyRightsPageTests(unittest.TestCase):
    def owner_info(self, content: str | None) -> dict[str, str]:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI obligatorio para ejecutar código real")
        with tempfile.TemporaryDirectory(prefix="condor-privacy-rights-") as folder:
            config = Path(folder) / "datos.yml"
            if content is not None:
                config.write_text(content, encoding="utf-8")
            completed = subprocess.run(
                [php, "-r", PHP_RUNNER, str(CONTROLLER), str(config)],
                capture_output=True, text=True, timeout=10, check=False,
            )
            self.assertEqual(0, completed.returncode, completed.stderr)
            return json.loads(completed.stdout)

    def test_criterio_principal(self) -> None:
        data = {
            "controller": {
                "name": "Empresa Sintetica SAS",
                "identifier": "NIT-SINTETICO",
                "address": "Direccion ficticia 123",
                "rights_email": "derechos@example.test",
            }
        }
        self.assertEqual(data["controller"], self.owner_info(json.dumps(data)))
        base = BASE.read_text(encoding="utf-8")
        page = PAGE.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")

        self.assertIn('<a href="{{ path(\'app_privacy_rights\') }}">Privacidad y mis derechos</a>', base)
        self.assertIn("<footer", base)
        self.assertIn("cookie-consent-settings", base)
        self.assertIn("cookie-consent-reject", base)
        self.assertIn("cookie-consent-accept", base)
        self.assertIn("'/privacidad-y-mis-derechos'", controller)
        self.assertIn("name: 'app_privacy_rights'", controller)
        self.assertIn("methods: ['GET']", controller)
        self.assertIn("{% extends 'base.html.twig' %}", page)
        for phrase in ("acceso", "corrección", "borrado", "responsable",
                       "Plazo de respuesta", "responsable.rights_email"):
            self.assertIn(phrase, page)
        self.assertIn("mailto:", page)
        self.assertIn("Condor #974", page)
        self.assertNotIn("|raw", page)  # Twig mantiene autoescape.

    def test_falla_cerrado(self) -> None:
        fields = ("name", "identifier", "address", "rights_email")
        self.assertEqual({f: PLACEHOLDER for f in fields}, self.owner_info(None))
        self.assertEqual({f: PLACEHOLDER for f in fields}, self.owner_info("{ no json"))
        self.assertEqual({f: PLACEHOLDER for f in fields}, self.owner_info('{"controller": []}'))
        self.assertEqual(
            {f: PLACEHOLDER for f in fields},
            self.owner_info(json.dumps({"controller": {
                "name": "", "identifier": 55, "address": ["desconocida"],
                "rights_email": "no-es-correo"
            }})),
        )
        result = self.owner_info(json.dumps({"controller": {
            "name": "Empresa Ficticia", "identifier": "SINT",
            "address": "Calle de prueba", "rights_email": "invalido"
        }}))
        self.assertEqual("Empresa Ficticia", result["name"])
        self.assertEqual(PLACEHOLDER, result["rights_email"])

    def test_sin_datos_reales(self) -> None:
        # El mapa actual se consulta solo con datos locales; nunca hay
        # peticiones HTTP, credenciales, solicitudes o mensajes enviados.
        data = json.loads(CONFIG.read_text(encoding="utf-8"))
        self.assertIn(data["phase"], ("construccion", "live"))
        owner = self.owner_info(json.dumps(data))
        self.assertEqual(set(owner), {"name", "identifier", "address", "rights_email"})
        if data["phase"] == "live":
            self.assertNotIn(PLACEHOLDER, owner.values(),
                             "No activar live sin responsable y contacto verificables")
        page = PAGE.read_text(encoding="utf-8")
        self.assertIn("Plazo aplicable para responder: <strong>{{ pendiente }}</strong>", page)
        self.assertIn("pendiente de revisión", page)
        self.assertNotIn("<form", page)
        self.assertNotIn("https://", page)
        self.assertNotIn("http://", page)


if __name__ == "__main__":
    unittest.main()
