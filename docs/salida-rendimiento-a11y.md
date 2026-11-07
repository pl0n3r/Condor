# Presupuesto de rendimiento y accesibilidad para salida — Condor #987

**Estado:** preparación técnica en fase `construccion`, sin datos reales ni despliegue. **Owner:** Condor (ingeniería + QA). **Corte:** main@34536f0f8f26d71c6d0e1183fde5636e6d0fba94. Este documento desarrolla la fila «Accesibilidad y rendimiento» de [la lista de salida](lista-salida-produccion.md); **NO VERIFICADO en navegador/producción** no significa aprobado.

## Propósito, alcance y responsables

Fijar presupuestos reproducibles para cuatro pantallas clave: inicio público, producto/solución comercial, acceso administrativo y panel administrativo. Ingeniería mantiene los recursos y sus límites; QA confirma regresiones y conserva recibos de ejecución; SRE valida el comportamiento desplegado **solo después** de una autorización separada de operación. Los límites de peso de fuentes se ejecutan en la aceptación del PR; no equivalen a tamaño de transferencia comprimida, tiempos de carga, peso total por red ni Web Vitals.

## Presupuesto automático del código en CI

En la tabla, **bytes** se calcula sobre el archivo UTF-8 versionado en HEAD mediante `Path.stat().st_size`; el test falla si supera `máximo` o si falta el archivo. Un recurso compartido se prueba una vez por ruta, y el límite más estricto prevalece. No es una medición real de navegador. **Evidencia de CI:** `tests/test_salida_rendimiento_a11y.py::SalidaTests::test_documento_cubre_el_alcance`, ejecutado por la aceptación Factory del PR; verificar `Validar` terminal sobre el SHA exacto.

| Pantalla | Recurso versionado | Métrica | Máximo |
| --- | --- | --- | --- |
| Inicio público | `templates/home/index.html.twig` | bytes | 24576 |
| Inicio público | `public/marketing.css` | bytes | 65536 |
| Producto público | `templates/marketing/producto.html.twig` | bytes | 20480 |
| Producto público | `public/marketing.css` | bytes | 65536 |
| Acceso administrativo | `templates/security/login.html.twig` | bytes | 8192 |
| Panel administrativo | `templates/admin/index.html.twig` | bytes | 8192 |
| Panel administrativo | `public/build/admin.js` | bytes | 393216 |
| Panel administrativo | `public/build/admin.css` | bytes | 32768 |

## Presupuesto de navegador y accesibilidad previo a live

Los siguientes son **objetivos técnicos aprobados exclusivamente para el laboratorio pre-live por la decisión OWNER #1868/A**, no resultados observados ni metas comerciales definitivas. Corresponden a cada una de las cuatro pantallas anteriores, bajo perfil móvil de red y CPU repetible con fixtures ficticias y sin servicios de pago. Toda ejecución futura debe conservar versión, SHA, URL o route sintética, viewport, dispositivo, herramienta, timestamp UTC y artefacto de resultados. Un resultado ausente es `NO VERIFICADO`; no se reemplaza por aprobación presunta.

| Pantalla | LCP p75 máximo (ms) | INP p75 máximo (ms) | CLS p75 máximo | Contraste texto normal mínimo | Contraste grande/UI mínimo | Violaciones axe serias/críticas máximas | Recorridos de teclado exigidos |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Inicio público | 2500 | 200 | 0.10 | 4.5:1 | 3:1 | 0 | 100% |
| Producto público | 2500 | 200 | 0.10 | 4.5:1 | 3:1 | 0 | 100% |
| Acceso administrativo | 2500 | 200 | 0.10 | 4.5:1 | 3:1 | 0 | 100% |
| Panel administrativo | 2500 | 200 | 0.10 | 4.5:1 | 3:1 | 0 | 100% |

**WCAG 2.2 AA es la meta, no una certificación.** El control existente `tests/test_ci_public_site_accessibility.py` carga `tests/test_public_site_accessibility.py` y ejecuta comprobaciones estáticas de etiquetas, estructura y contraste del sitio público en el job de pruebas base mediante `python3 -m unittest discover -s tests -p 'test_ci_*.py'`. Mantiene deuda explícita de #788; no demuestra por sí solo axe, navegación de teclado, lector de pantalla, panel privado, LCP/INP/CLS ni ausencia de regresiones visuales. El contrato de #987 agrega **presupuestos de peso estático efectivamente medidos**, valida la presencia del enlace con el CI y fija los objetivos de navegador, **sin afirmar que Lighthouse o axe corran hoy en CI**.

## Pasos y evidencia requerida

1. **Ingeniería:** revisar recursos versionados de las cuatro pantallas y ejecutar localmente `python3 -m unittest tests/test_salida_rendimiento_a11y.py -v`; registrar SHA y superar todos los límites medidos.
2. **QA:** comprobar `Criterios de aceptación` de Factory y la regresión existente de accesibilidad pública en CI; obtener `Validar` `success` sobre el HEAD definitivo. Una prueba saltada, fallida o sin evidencia no es verde.
3. **QA antes de live:** configurar un escenario aislado de navegador con usuarios y datos ficticios; medir Web Vitals con metodología p75 y Lighthouse/axe, documentar cumplimiento WCAG 2.2 AA, foco visible, etiquetas, lectura, navegación por teclado y estados de error/denegación para cada pantalla. **PENDIENTE: no hay recibo de laboratorio integral**.
4. **SRE/operación:** después de autorización humana separada y puertas de recuperación/privacidad, medir de forma observacional la identidad exacta del despliegue y los presupuestos en la infraestructura real. Si el umbral falla, bloquear promoción, guardar evidencia y abrir reparación acotada. No se ejecuta ninguna prueba contra producción en este issue.

## DECISIÓN DEL DUEÑO — cuentas, gastos y salida

- **DECISIÓN DEL DUEÑO:** autorizar la herramienta o cuenta de laboratorio externo, las credenciales de prueba que pudiera requerir, costos, cuota y cualquier proveedor. **SIN DECIDIR; no se contrata ni se usa**.
- **DECISIÓN DEL DUEÑO:** aprobar el presupuesto real por tipo de dispositivo, experiencia y clientes junto con el criterio comercial de excepción. Los números anteriores son metas técnicas propuestas, no aprobación comercial.
- **DECISIÓN DEL DUEÑO:** autorizar explícitamente acceso a hosting, dominio real, pruebas productivas, datos reales, publicación y `go-live`; todas esas operaciones están **NO AUTORIZADAS** para esta entrega.
- **DECISIÓN DEL DUEÑO:** designar responsable de atención a incidentes y de aceptación legal/accesibilidad previo a live. No se capturan nombres, emails de clientes, credenciales, tokens, trazas sensibles ni sesiones reales.

## Matriz técnica pre-live aprobada — OWNER #1868/A

**Decisión comprobada:** [Condor #1868](https://github.com/pl0n3r/Condor/issues/1868), voto OWNER aislado `/decidir A` (comentario #6713272297), journal BOT v2/A (comentario #6713278956) y puerta CLOSED/completada. **Corte documental:** `main@dacd54caaeb745c5c666422ca191aba6562b5aaa` (identidad de partida del contrato, no SHA de una medición). La autoridad es únicamente técnica y previa a live; no aprueba proveedores, presupuesto comercial, sesiones reales ni go-live.

**Matriz objetivo, no resultados:** Chrome estable y **Playwright WebKit** (no Safari nativo) en escritorio **1440×900** y móvil **390×844**. Cinco rutas × dos motores × dos perfiles = **20 escenarios objetivo**. La hija #1819 observó **12** escenarios históricos exploratorios en rutas públicas/login; los otros **8** escenarios de paneles con sesiones exclusivamente ficticias permanecen **NO VERIFICADO**.

<!-- QA1499-APPROVED-MATRIX-START -->
| Flujo | Ruta | Chrome estable | Playwright WebKit | Estado de evidencia |
| --- | --- | --- | --- | --- |
| Inicio público | `/` | 2 | 2 | PARCIAL — axe de reglas seleccionadas, sin p75 de campo |
| Producto público | `/producto` | 2 | 2 | PARCIAL — axe de reglas seleccionadas, sin p75 de campo |
| Acceso administrativo | `/admin/login` | 2 | 2 | PARCIAL — axe de reglas seleccionadas, sin p75 de campo |
| Panel tenant sintético | `/admin` | 2 | 2 | PENDIENTE — requiere sesión sintética y recorrido de navegador |
| Panel plataforma sintético | `/adminpl0n3r` | 2 | 2 | PENDIENTE — requiere sesión sintética y recorrido de navegador |
<!-- QA1499-APPROVED-MATRIX-END -->

**Metas técnicas provisionales autorizadas solo para laboratorio:** LCP p75 ≤ 2500 ms; INP p75 ≤ 200 ms; CLS p75 ≤ 0,10; hallazgos **axe serios/críticos: 0**; recorrido de **teclado 100%**. Para futuras ejecuciones guardar método, tamaños de muestra, UTC, motor/versiones, viewport, fixtures ficticias, URL únicamente localhost, artefactos sanitizados y **SHA exacto del árbol servido**. Una muestra exploratoria no constituye field p75 representativo ni INP canónico; si faltan interacciones válidas registrar `NO VERIFICADO`, nunca PASS inferido.

**Puertas independientes:** Safari nativo, dispositivos físicos, lectores de pantalla, conformidad integral WCAG 2.2 AA, presupuestos comerciales, proveedores externos, costes, datos reales, cuentas de clientes, host público, telemetría de campo y go-live **NO VERIFICADO / NO AUTORIZADO**. El laboratorio Chrome/Playwright WebKit no certifica Safari ni la release. La decisión de go-live sigue separada en #1498. Esta matriz no modifica presupuestos de bytes de CI.

**Reversión:** revertir el squash de los dos archivos documentales; sin impacto sobre producto, infraestructura, migraciones, costos o producción.
