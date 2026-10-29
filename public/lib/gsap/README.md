# GSAP, servido desde el propio dominio

Archivos minificados de GSAP 3.15.0 (el núcleo y SplitText), tomados tal cual del paquete `gsap@3.15.0` del registro de npm (https://registry.npmjs.org/gsap/-/gsap-3.15.0.tgz, carpeta `dist/`). No se modifican.

| Archivo | SHA-256 |
| --- | --- |
| `gsap.min.js` | `92bb9a96476f983d212a2bc4f54c889039c1696dd4461d40a736860938570fbb` |
| `SplitText.min.js` | `419f7027a5f086a12cb7988736d8fdd3a6ed2200229661de25b6628ca7ced344` |

`tests/test_marketing_motion.py` comprueba estos hashes: cambiar un archivo exige actualizar esta tabla y esa prueba a propósito.

## Licencia

GSAP se usa bajo la licencia estándar «sin costo» de GreenSock: https://gsap.com/standard-license. El encabezado de cada archivo la cita y no se debe quitar. La licencia prohíbe usar GSAP dentro de herramientas visuales que compitan con las de Webflow; este uso es solo el sitio público de Condor, no una herramienta para clientes. Si el constructor de sitios por bloques de Condor llegara a ofrecer animaciones a sus clientes, hay que revisar la licencia antes.

## Por qué no se incluye ScrollTrigger

ScrollTrigger guarda y restaura los estilos en línea de cada elemento con `style.cssText = …` en cada recálculo. La política de contenido del sitio (`style-src 'self'`) bloquea esa asignación, así que llenaba la consola de errores y dejaba sin restaurar esos estilos. La entrada al hacer scroll la decide un `IntersectionObserver` nativo y GSAP anima. No relajar la política para esto; si se quiere ScrollTrigger, hay que decidirlo aparte y medirlo contra la política.

## Dónde se usa

Solo en el sitio público (`templates/home` y `templates/marketing`), a través de `public/marketing-motion-gate.js` y `public/marketing-motion.js`. El panel de administración no lo carga. La política de contenido (`script-src 'self'`) ya permite estos archivos; no hay terceros.
