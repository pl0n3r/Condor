# Laboratorio de navegadores offline — Condor #1499 / hija #1819

**Corte observado:** `Condor/main@cd01e123fff4513f5082a9116e2b285ba975ccbe`, 2026-11-07 09:06:48 UTC. **Alcance:** preparación técnica de QA; **NO VERIFICADO** para aprobación de release. No autoriza go-live.

## Evidencia reproducible de navegador aislado

Se extrajo un árbol limpio con `git archive` del commit indicado, sin modificar el checkout principal. PHP/Symfony se levantó solo en `127.0.0.1` con `APP_ENV=test`, clave sintética y SQLite descartable. Se rechazaron solicitudes externas del contexto Playwright, sin autenticación real, credenciales de clientes ni proveedores de pago.

| Motor | Perfiles | Rutas | HTTP | axe-core 4.10.3, reglas seleccionadas |
| --- | --- | --- | --- | --- |
| Chrome 156 | escritorio 1440×900, móvil 390×844 | `/`, `/producto`, `/admin/login` | 6/6 HTTP 200 | 0 violaciones en 6 casos |
| Playwright WebKit 26.6 | escritorio 1440×900, móvil 390×844 | `/`, `/producto`, `/admin/login` | 6/6 HTTP 200 | 0 violaciones en 6 casos |
| **Total** | **2×2** | **3 rutas** | **12/12 HTTP 200** | **0 en 12 casos** |

Reglas axe ejecutadas: `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`. CSS `/marketing.css` se sirvió HTTP 200 y se cargó en las rutas públicas. En `/producto`, `.mk-tablist` y `#modulo-catalogo > .mk-bd` fueron enfocables y nombradas en ambos motores/perfiles (fix #1804 ya fusionado).

**Control de calidad contra falsos contrastes:** la primera ejecución WebKit sobre `/` reportó 20 nodos `color-contrast` dentro de `.mk-pick` mientras estaba ocurriendo la transición de revelado. Se verificó que ese contenedor inicia con `opacity=0`/`visibility=hidden` y que tras desplazarlo y estabilizar `opacity=1`/`visibility=visible` la **auditoría completa, sin excluir elementos**, reportó 0 violaciones. El CLI espera la estabilidad antes de auditar, no modifica DOM/producto para hacer pasar axe. El reporte inicial transitorio no acredita 20 defectos estables ni sustituye pruebas de animación accesible.

**No generalizar:** este recorte solo cubre tres rutas públicas/login, reglas axe seleccionadas y motores de navegador (Playwright WebKit **no** equivale a verificar la aplicación Safari nativa). La comprobación de atributos de foco de dos regiones no acredita recorrido integral de teclado, lector de pantalla, contraste manual o conformidad WCAG 2.2 AA.

## Reproducción manual fail-closed

Ejecutar primero `node scripts/qa1499-browser-lab.cjs --self-test`. Desde un checkout del SHA exacto, servir Symfony con `APP_ENV=test` y fixtures sintéticas en un **servidor solo localhost**, con JS/CSS correctos. Instalar, si hacen falta, los navegadores gratuitos con el Playwright ya declarado en el proyecto; usar un archivo local oficial `axe.min.js` de **axe-core 4.10.3**. No habilitar conexiones live, sesiones privadas, hostname público ni dependencias externas del navegador.

Ejemplo de invocación (rutas y parámetros solo locales; las rutas del archivo axe y de salida son del laboratorio del operador):

```sh
export QA1499_BASE_URL=http://127.0.0.1:18772
export QA1499_ACK_SYNTHETIC_TEST_ONLY=1
export QA1499_HEAD_SHA=cd01e123fff4513f5082a9116e2b285ba975ccbe
export QA1499_AXE_SCRIPT=/ruta/local/axe-core-4.10.3/axe.min.js
export QA1499_OUTPUT=/ruta/local/recibo-sintetico.json
node scripts/qa1499-browser-lab.cjs
python3 -m unittest tests.test_qa1499_browser_lab -v
```

El CLI rechaza URL externa/no loopback, HTTPS, credenciales en URL, parámetros, hash, confirmación sintética faltante, SHA no válido, ausencia de axe o scripts de navegador no disponibles. Las requests de la página se limitan al origen local exacto. Guarda únicamente método, motor, ruta, viewport, estado, regla/selector y errores resumidos; no lee textos de formularios ni tokens. `QA1499_HEAD_SHA` es la identidad **declarada** en el recibo: quien ejecuta debe acreditar por separado que el árbol fue extraído realmente de ese SHA. El bypass CSP solo se aplica al contexto sintético para inyectar axe, sin modificar cabeceras de producción.

## Puertas pendientes de #1499

**NO VERIFICADO:** LCP, INP y CLS p75 representativos (la muestra local no es telemetría de campo), interacciones INP real, matrices/ presupuestos comerciales aprobados, usuarios ficticios autenticados de panel **tenant** y **plataforma**, teclado completo, lector de pantalla, Safari app nativa y certificación WCAG 2.2 AA. No se ha validado backend/hosting, backup/restore ni go-live; no se habilita tráfico real.

**Fallo adicional reproducido:** `tests/test_salida_rendimiento_a11y.py` informa `public/marketing.css` **68827 bytes > 65536**. Lo modifica la reserva V3 activa de **#1425** / PR #1786. Esta hija no reclama ni edita ese archivo; su titular debe corregir el presupuesto dentro de su propia reserva o liberar la ruta. No aumentar el presupuesto para fingir aprobación.

**Decisión:** evidencia útil de preparación aislada, no satisface los cuatro AC de la release #1499. No autoriza go-live ni declara la matriz integral completada. Revert del PR de #1819 elimina solo la herramienta, la prueba y este documento, sin afectar despliegue ni datos.
