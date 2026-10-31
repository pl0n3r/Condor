"""Condor #1439: consentimiento de cookies seguro y reproducible sin red."""
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / 'templates/base.html.twig'
CSS = ROOT / 'public/cookie-consent.css'
SCRIPT = ROOT / 'public/cookie-consent.js'

NODE_HARNESS = r'''
const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const code = fs.readFileSync(process.argv[1], 'utf8');
function session(initial = '', pathname = '/') {
  let cookie = initial;
  const loaded = [];
  const elements = {};
  for (const id of ['cookie-consent-banner','cookie-consent-accept',
    'cookie-consent-reject','cookie-consent-close','cookie-consent-settings']) {
    elements[id] = {hidden:true, events:{}, dataset:{},
      addEventListener(event,fn){this.events[event]=fn;},
      focus(){this.focused=true;},
      click(){this.events.click();}};
  }
  const optional = {dataset:{cookieSrc:'https://example.test/optional.js'}};
  const document = {
    get cookie(){return cookie;},
    set cookie(value){cookie = value.split(';')[0];},
    getElementById(id){return elements[id];},
    querySelectorAll(query){assert.equal(query,'script[type="text/plain"][data-cookie-src]');return [optional];},
    createElement(tag){assert.equal(tag,'script');return {};},
    head:{appendChild(script){loaded.push(script.src);}},
  };
  const window = {location:{href:'https://condor.test/',origin:'https://condor.test',
    protocol:'https:',pathname,reload(){window.reloadCount++;}},reloadCount:0};
  vm.runInNewContext(code,{document,window,URL}, {timeout:1000});
  return {elements,loaded,optional,window,get cookie(){return cookie;}};
}
const unset=session();
assert.equal(unset.elements['cookie-consent-banner'].hidden,false);
assert.equal(unset.loaded.length,0);
assert.equal(unset.elements['cookie-consent-reject'].focused,true);
unset.elements['cookie-consent-close'].click();
assert.match(unset.cookie,/^condor_cookie_choice=rejected$/);
assert.equal(unset.loaded.length,0);
const rejected=session('condor_cookie_choice=rejected');
assert.equal(rejected.elements['cookie-consent-banner'].hidden,true);
assert.equal(rejected.loaded.length,0);
rejected.elements['cookie-consent-settings'].click();
assert.equal(rejected.elements['cookie-consent-banner'].hidden,false);
rejected.elements['cookie-consent-accept'].click();
assert.match(rejected.cookie,/accepted$/);
assert.deepEqual(rejected.loaded,['https://example.test/optional.js']);
const accepted=session('condor_cookie_choice=accepted');
assert.equal(accepted.loaded.length,1);
accepted.elements['cookie-consent-settings'].click();
accepted.elements['cookie-consent-reject'].click();
assert.equal(accepted.loaded.length,1); // ya cargado, requiere recarga
assert.equal(accepted.window.reloadCount,1);
assert.match(accepted.cookie,/rejected$/);
const unknown=session('condor_cookie_choice=unexpected');
assert.equal(unknown.loaded.length,0);
assert.equal(unknown.elements['cookie-consent-banner'].hidden,false);
// El flujo de backoffice no puede bloquear controles ni cargar scripts opcionales.
for (const path of ['/admin/login', '/adminpl0n3r/empresas']) {
  const admin = session('condor_cookie_choice=accepted', path);
  assert.equal(admin.elements['cookie-consent-banner'].hidden,true);
  assert.equal(admin.loaded.length,0);
}
console.log('cookie consent browser simulation: OK');
'''

class CookieConsentTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        html = BASE.read_text(encoding='utf-8')
        css = CSS.read_text(encoding='utf-8')
        js = SCRIPT.read_text(encoding='utf-8')
        for marker in ('cookie-consent-banner', 'cookie-consent-accept',
                       'cookie-consent-reject', 'cookie-consent-settings',
                       'cookie-consent-close', 'aria-labelledby',
                       'cookie_optional_scripts', 'PENDIENTE DE APROBACIÓN'):
            with self.subTest(marker=marker):
                self.assertIn(marker, html)
        self.assertIn('src="/cookie-consent.js" defer', html)
        self.assertIn('href="/cookie-consent.css"', html)
        self.assertIn('type="text/plain"', html)
        self.assertNotIn('checked', html)
        self.assertIn('min-height: 44px', css)
        self.assertIn(':focus-visible', css)
        self.assertIn('prefers-reduced-motion: reduce', css)
        self.assertIn('.cookie-consent__action', css)
        self.assertIn('SameSite=Lax', js)
        self.assertIn('Max-Age=', js)
        self.assertIn('Secure', js)
        self.assertNotIn('google-analytics', js.lower())
        self.assertIsNotNone(shutil.which('node'), 'Node.js requerido para probar los eventos reales')
        run = subprocess.run(['node', '-e', NODE_HARNESS, str(SCRIPT)],
                             cwd=ROOT, text=True, capture_output=True,
                             timeout=15, check=False)
        self.assertEqual(run.returncode, 0, run.stdout + run.stderr)
        self.assertIn('browser simulation: OK', run.stdout)

    def test_estilos_y_fuentes_sin_terceros(self) -> None:
        css = CSS.read_text(encoding='utf-8')
        html = BASE.read_text(encoding='utf-8')
        self.assertNotIn('@import', css)
        self.assertNotIn('http://', html)
        self.assertNotIn('https://', html.split('{#')[0])

if __name__ == '__main__':
    unittest.main()
