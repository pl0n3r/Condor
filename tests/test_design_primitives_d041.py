"""D-041-S04/S06: regresiones de primitives, teclado, foco y viewport de 360 px.

Contrato estático obligatorio; integración de navegador cuando Playwright Chromium
está disponible, sin datos ni servicios externos.
"""
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "frontend/admin/DesignPrimitives.tsx"
EVIDENCE = ROOT / "docs/verificacion/d041-mobile-primitives.md"


def require_dialog_contract(source):
    for witness in (
        "dialog.showModal()", "dialog.close()", 'role="dialog"',
        'aria-modal="true"', "aria-labelledby={titleId}",
        "onCancel={(event) =>", "event.preventDefault()",
        "closeRef.current?.focus()", "opener.focus()", "opener?.isConnected",
    ):
        if witness not in source:
            raise AssertionError("Falta testigo de modal/teclado/foco: " + witness)


def require_field_contract(source):
    for witness in (
        "<label htmlFor={id}>{label}</label>", "useId()",
        "aria-describedby={describedBy}", "aria-invalid={error ? true : undefined}",
        'id={errorId} role="alert"', "id={helpId}",
    ):
        if witness not in source:
            raise AssertionError("Falta testigo de etiqueta/ayuda/error: " + witness)


def require_button_contract(source):
    for witness in (
        "<button", "{...rest}", "type = 'button'",
        "minHeight: 44", "minWidth: 44",
    ):
        if witness not in source:
            raise AssertionError("Falta testigo de interacción: " + witness)


class DesignPrimitivesD041Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SOURCE.read_text(encoding="utf-8")

    def test_native_button_keyboard_and_touch_area(self):
        require_button_contract(self.source)
        self.assertNotIn('role="button"', self.source)
        self.assertIn("export function Button(", self.source)
        self.assertIn("variant === 'secondary'", self.source)

    def test_field_label_help_and_error(self):
        require_field_contract(self.source)
        self.assertIn("export function Field(", self.source)
        self.assertIn("width: '100%'", self.source)
        self.assertIn("boxSizing: 'border-box'", self.source)

    def test_dialog_keyboard_escape_and_focus(self):
        require_dialog_contract(self.source)
        self.assertIn("export function Dialog(", self.source)
        self.assertIn("if (!dialog.open) dialog.showModal();", self.source)

    def test_mobile_viewport_guards(self):
        for witness in (
            "maxWidth: 'calc(100vw - 2rem)'",
            "width: 'min(32rem, calc(100vw - 2rem))'",
            "maxHeight: 'calc(100dvh - 2rem)'",
            "overflow: 'auto'",
            "overflowWrap: 'anywhere'",
        ):
            with self.subTest(witness=witness):
                self.assertIn(witness, self.source)

    def test_negative_mutations_fail_contract(self):
        """La regresión falla si se retira cada control de seguridad."""
        cases = [
            (require_button_contract, "minWidth: 44"),
            (require_button_contract, "<button"),
            (require_field_contract, "aria-describedby={describedBy}"),
            (require_field_contract, 'id={errorId} role="alert"'),
            (require_dialog_contract, "dialog.showModal()"),
            (require_dialog_contract, "opener.focus()"),
            (require_dialog_contract, "onCancel={(event) =>"),
        ]
        for validator, witness in cases:
            with self.subTest(witness=witness), self.assertRaises(AssertionError):
                validator(self.source.replace(witness, ""))

    def test_evidence_remains_fail_closed(self):
        document = EVIDENCE.read_text(encoding="utf-8")
        for witness in ("D-041-S04", "D-041-S06", "360 px", "NO_VERIFICABLE", "Playwright"):
            self.assertIn(witness, document)

    def test_browser_keyboard_focus_and_viewport_360(self):
        """Prueba real de interacción, cuando el runner instala Chromium."""
        if not shutil.which("node") or not (ROOT / "node_modules" / "esbuild").exists():
            self.skipTest("Node/esbuild ausentes; contrato estático permanece obligatorio")
        script = r"""
import { build } from 'esbuild';
import { chromium } from '@playwright/test';
import { existsSync } from 'node:fs';

if (!existsSync(chromium.executablePath())) {
  process.stdout.write('BROWSER_NOT_INSTALLED');
  process.exit(77);
}
const fixture = [
  'import React, { useState } from "react";',
  'import { createRoot } from "react-dom/client";',
  'import { Button, Field, Dialog } from "./frontend/admin/DesignPrimitives.tsx";',
  'function Example() {',
  '  const [open, setOpen] = useState(false);',
  '  return <main style={{width:"100%",boxSizing:"border-box"}}>',
  '    <Button onClick={() => setOpen(true)}>Abrir</Button>',
  '    <Field label="Nombre" help="Ayuda" error="Requerido" inputProps={{name:"nombre"}} />',
  '    <Dialog open={open} title="Confirmación" onClose={() => setOpen(false)}>',
  '      <p>Solo datos sintéticos</p>',
  '    </Dialog></main>;',
  '}',
  'createRoot(document.getElementById("app")).render(<Example />);'
].join('\n');
const bundle = await build({
  stdin: { contents: fixture, resolveDir: process.cwd(), sourcefile: 'fixture.tsx', loader: 'tsx' },
  bundle: true, platform: 'browser', format: 'iife', write: false, jsx: 'automatic'
});
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 360, height: 700 } });
  await page.setContent('<div id="app"></div>');
  await page.addScriptTag({ content: bundle.outputFiles[0].text });
  const opener = page.getByRole('button', { name: 'Abrir' });
  await opener.waitFor();
  await opener.focus();
  await page.keyboard.press('Space');
  const dialog = page.getByRole('dialog', { name: 'Confirmación' });
  await dialog.waitFor();
  const focused = await page.evaluate(() => document.activeElement?.textContent?.trim());
  if (focused !== 'Cerrar') throw new Error('Modal no enfocó el botón Cerrar');
  await page.keyboard.press('Tab');
  if (!(await dialog.isVisible())) throw new Error('Tab cerró el modal');
  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'hidden' });
  if (!(await opener.evaluate(el => el === document.activeElement))) {
    throw new Error('Escape no devolvió el foco al botón disparador');
  }
  const input = page.getByRole('textbox', { name: 'Nombre' });
  if ((await input.getAttribute('aria-invalid')) !== 'true') {
    throw new Error('Campo no anuncia su estado inválido');
  }
  if (!(await input.getAttribute('aria-describedby'))) {
    throw new Error('Campo carece de relación con ayuda y error');
  }
  if (!(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))) {
    throw new Error('Desbordamiento horizontal a 360 px');
  }
} finally {
  await browser.close();
}
"""
        result = subprocess.run(
            ["node", "--input-type=module", "--eval", script],
            cwd=ROOT, text=True, capture_output=True, timeout=75, check=False,
        )
        if result.returncode == 77 and "BROWSER_NOT_INSTALLED" in result.stdout:
            self.skipTest("Chromium de Playwright no instalado; ejecutar en runner E2E")
        self.assertEqual(result.returncode, 0, result.stdout + "\n" + result.stderr)


if __name__ == "__main__":
    unittest.main()
