# Condor #1556 — recibos externos del README sin simular producción

Corte de fuentes: **2026-11-05**, trabajo exclusivamente read-only y fixtures
aislados. Checkout de pruebas frontend/SSR: Condor main@fa0cfae84328a050a5a74580bf3f3452a10e7b9b (no es deployment). Referencia: #1551, PR #1557 (fusionado), inventario de 29 filas en
docs/verificacion/verify-readme-claims.md. Reserva V3 propia:
616435c1-9886-4d6a-bad2-e64ca1728851. No modificar README, matriz ni
versión. El checkout del test **no** prueba Hostinger, disponibilidad de
clientes, salud de producción, permisos reales de operador o go-live.

## 1. Badges y cola de GitHub: fuente viva y límites

| Fuente | URL / identidad | Recibo observado | Límite |
| --- | --- | --- | --- |
| README-01-01: CI badge | https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg?branch=main | HTTP 200, SVG, 2026-11-05T22:38:22Z | Respuesta HTTP de la insignia, NO CI exact-SHA ni producción |
| README-01-01: release badge | https://img.shields.io/github/v/release/pl0n3r/Condor?label=release | HTTP 200, SVG, 2026-11-05T22:38:22Z | El proveedor externo puede cachear; no es recibo de deploy |
| Releases | https://api.github.com/repos/pl0n3r/Condor/releases?per_page=2 | v0.1.198, publicado 2026-11-04T20:42:52Z, target_commitish=main | Un tag o target mutable no acredita versión/SHA desplegados |
| Actions | https://api.github.com/repos/pl0n3r/Condor/actions/runs?branch=main&per_page=5 | Incluía run 42123047435, success para 5725f91dbbf2e24426d7a9ea0efa57131c9a0192 | Un run no demuestra que otro HEAD esté validado |

Consultas GET de GitHub API hechas en la ventana exacta
**2026-11-05T22:42:31.914Z–22:42:37.649Z**, con per_page=100, page=1.
El JSON devolvió incomplete_results=false, número de items igual a total_count,
sin segunda página pendiente para estas cuatro consultas:

| Etiqueta de README | Filtro GitHub REST literal (añadir repo:pl0n3r/Condor) | total_count / recibidos |
| --- | --- | --- |
| NOW reservados | is:issue is:open label:"estado: reservado" | 4 / 4 |
| NOW en revisión | is:issue is:open label:"estado: en revisión" | 3 / 3 |
| NEXT | is:issue is:open label:"prioridad: crítica" label:"estado: disponible" | 0 / 0 |
| BLOCKED | is:issue is:open label:"estado: bloqueado" | 24 / 24 |

Cada filtro tiene origen rastreable bajo
https://api.github.com/search/issues y su enlace equivalente en README.md.
El verificador ci_check_receipt exige origen REST GitHub
/repos/pl0n3r/Condor/commits/<SHA>/check-runs, nombre
CI Factory v1 / Validar, head_sha idéntico, fuente github-actions, estado
completed/success y URL de job verificable. Su salida CI_SUCCESS_ONLY no
certifica producción. El test negativo incluye status pending/skipped,
otro SHA, otro check, fecha caducada o dominio distinto.

La foto puede cambiar **inmediatamente después de obtenida**. Los filtros
solo clasifican etiquetas; NO demuestran reserva V3, titular, lease válida,
dependencias completadas ni permisos. Esos hechos exigen leer Issue, comentarios
paginados originales, marker bot v3 más reciente, estado activo, branch, claims,
dependencias y autorización del dueño. No se infieren desde total_count.

El verificador offline de tests/integration/test_readme_external_evidence.py
requiere URL canónica, repo/issue exactos, timestamp con zona y frescura,
páginas contiguas, Link next al siguiente URI real, ausencia de resultados
incompletos, conteos consistentes y sin IDs repetidos. Una página parcial,
resultado externo o item sin etiquetas obligatorias falla cerrado. El origen
del JSON lo entrega un consumidor GitHub **autenticado**: fixtures no se
autentican por inventar el campo source=github-rest.

## 2. Admin real aislado y Twig SSR sintético, nunca producción

En el checkout de este corte se ejecutó con dependencias de lock:
- npm ci --ignore-scripts --no-audit --no-fund: 73 paquetes, success.
- npm run typecheck: success.
- npm run build:admin: Vite 7.3.7, success; produce
  public/build/admin.js, admin.css y chunk de React.
- Chrome/Chromium **real** 156.0.8078.80 mediante Playwright 1.63.0.
  Origin aislado **http://127.0.0.1** servido por proceso temporal;
  cargó el bundle React compilado con un stub de API que **siempre responde
  403** (sin sesión real). Resultado HTTP 200 del fixture, React muestra
  explícitamente «No pudimos cargar el contexto de tu empresa»;
  1 petición /api/v1/context denegada, 0 errores JavaScript observados.
  SHA-256 de admin.js: 9b248e5c412869613dce504a4e795e93fae4645e61245502e359807e7164c6cf.
- Twig **real** 3.30.0 en directorio Composer temporal y aislado, cargando
  el template verdadero templates/admin/index.html.twig con funciones
  path y csrf_token simuladas. Con tenant_name sintético conteniendo
  marcado HTML, la salida escapó los caracteres peligrosos; faltando
  tenant_name la renderización strict_variables falló, como negativo.
  HTML de 2.989 bytes, SHA-256
  ad1e2b32e0439d86481c614d5800abf5ff6aeb13ea207ba56d8f82a536324220.

Estos recibos se refieren al **checkout local de ese momento**, nunca al
reloj del sitio ni al último release. Ni un fixture con Chrome ni un render
Twig sustituyen E2E completo bajo sesión autorizada y BD aislada. El contrato
browser_contract exige éxito de build/Chromium/SSR ligados a **un mismo SHA**,
origen localhost, artefactos identificados, un caso denegado y escape HTML.
Un fixture que lo satisface recibe solo SYNTHETIC_CONTRACT_ONLY.

## 3. Observer, release y barrera D-043/D-059

READMEs 05-04/05, 07-04 y 08-04: hay que distinguir estos estados:

- CI_SUCCESS: check oficial terminal, autenticado, nombre obligatorio y SHA
  exacto del commit que se evalúa, según GitHub Actions. El badge no basta.
- BUILD_BROWSER_FIXTURE_VERIFIED: browser aislado y Twig real bajo fixtures
  sintéticos. No hay usuarios ni datos de cliente.
- VALIDATED_IN_PRODUCTION: **solo** recibo terminal del observer autorizado,
  verificado contra versión + SHA exactos del despliegue real, URL del run
  oficial, bytes/sha256 del artifact auténtico y pending/critical vacíos,
  con puertas humanas D-043/D-059, backups/rollback/migraciones y aprobación
  independientes cuando corresponda. No sale de un test offline.

El contrato observer_contract falla cerrado con UNKNOWN cuando falta una
fuente independiente autenticada. El indicador authenticated es una atestación
**externa** del validador de procedencia; está prohibido derivarlo del JSON
de entrada o del propio fixture. Aun pasando la validación estructural el
resultado es STRUCTURALLY_ELIGIBLE_NOT_PRODUCTION_PROOF, **nunca** una
declaración automática de VALIDATED_IN_PRODUCTION.

**Resultado productivo de este corte: UNKNOWN.** No se consultó Hostinger
ni se tocaron servicios live, secrets, DNS, cuentas o usuarios; no se asumió
ningún backup/rollback auténtico.

## 4. Pruebas, regresión y reversión

Ejecutar offline:

    python3 -m unittest -v tests.integration.test_readme_external_evidence

La suite cubre: filtros README exactos, paginación total y parcial, datos
cruzados de otro repo, repetidos, fecha vieja, reserva falsificada/revocada,
badge de otra rama, bundle/browser/SSR sin negativos, y observer viejo,
no terminal o con SHA/pending incompatibles. No hace llamadas de red.

Cuando haya fuente operativa autorizada, un testigo externo puede alimentar
el validador con recibos reales **sin convertir las fixtures en prueba live**.
Una regresión debe volverse roja al retirar las guardas, no se autoriza
relajar el contrato para conseguir verde. Revert del squash elimina
exclusivamente este documento y su test, sin efectos productivos.
