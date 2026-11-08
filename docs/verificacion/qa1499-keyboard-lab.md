# Laboratorio de teclado local · Condor #1499 / hoja #1877

Este instrumento deriva de la matriz técnica pre-live aprobada por OWNER #1868/A (journal v2/A). Opera **solo localhost**, con árbol del **SHA exacto** conocido, páginas ficticias y **sin credenciales** ni cuentas reales. No necesita proveedores o servicios externos y **no autoriza go-live**.

## Cobertura y límites

12 escenarios exploratorios: tres rutas GET /, GET /producto, GET /admin/login × Chrome estable / Playwright WebKit × escritorio 1440×900 y móvil 390×844. **Playwright WebKit no es Safari nativo**. No se prueban los ocho escenarios privados tenant/plataforma restantes: requieren otro contrato de fixture, rutas y sesiones estrictamente sintéticas.

Cada caso observa 24 pulsaciones de Tab y cuenta objetivos de foco distintos, apariciones de :focus-visible y presencia booleana de nombre accesible en elementos enfocados. No recoge contenido de elementos, valores de formularios, cookies, tokens, capturas ni PII. Los conteos se serializan en JSON por stdout con motor, viewport, ruta, versión del recibo y sourceShaDeclared como identidad **declarada**; el operador debe demostrar por separado que el servidor usa realmente ese SHA exacto.

Los estados **PARCIAL** y **NO_VERIFICADO** se mantienen incluso cuando se observan focos: no comprueban activación por Enter/Espacio, navegación completa por todos los estados, lectores de pantalla, contraste manual, comportamiento bajo auténtica sesión, Web Vitals de campo ni teclado 100%. **Este laboratorio NO certifica WCAG 2.2 AA** ni acepta la release. No atribuirle p75 LCP/INP/CLS, Safari físico o go-live.

## Ejecución opcional de solo lectura

Sobre un checkout limpio del SHA de Condor evaluado, con npm ci --offline --ignore-scripts ya disponible, arranque servidor PHP/Symfony **exclusivamente** sobre 127.0.0.1 con APP_ENV=test y base ficticia/descartable. No usar cuentas, hosts ni datos reales.

- node scripts/qa1499-keyboard-lab.cjs --self-test
- python3 -m unittest -v tests.test_qa1499_keyboard_lab
- export QA1499_BASE_URL=http://127.0.0.1:18771
- export QA1499_ACK_SYNTHETIC_TEST_ONLY=1
- export QA1499_HEAD_SHA=SHA-exacto-40-hex-del-arbol-servido
- node scripts/qa1499-keyboard-lab.cjs

El proceso **falla cerrado** si falta un navegador, hay redirección a login no esperado, URL ajena o HTTP no satisfactorio. Solo admite HTTP loopback y bloquea recursos/WebSockets de otro origen. Descartar salidas parciales y conservar NO_VERIFICADO cuando no hay muestra. Si se desea archivar el recibo, redirigir stdout a un archivo fuera del checkout y comprobar que no contiene información sensible.

## Pendientes del padre

#1499 sigue OPEN: faltan los ocho escenarios tenant/plataforma auténticamente sintéticos, instrumentación real INP/p75 representativo, inspección integral de teclado/axe, Safari nativo/dispositivos físicos y presupuesto comercial. La decisión separada de go-live sigue en #1498. Un merge de esta herramienta no afirma los AC globales de release.

**Motor no disponible:** si Chrome o Playwright WebKit no están instalados, la herramienta conserva las filas de ese motor como `NO_VERIFICADO` en el recibo pero termina con código distinto de cero; un recibo parcial **no** acredita los 12 escenarios.
