# M01 S02–S11: aceptación visual sintética pendiente (#1817)

**Estado: PREPARACIÓN, NO ACEPTADO.** Ningún check verde de otros PR ni esta hoja documental certifica los recorridos E2E, la legalidad de tratamientos ni la operación en vivo. Epic #1567.

## Autoridad y antecedentes

- Condor #742 mantiene decisión humana autenticada **opción B**; RR. HH. y asistencia con datos reales siguen deshabilitados. #1497 no tiene aprobación jurídica profesional.
- #1651/PR #1777 incorporó la UI genérica de registros, no las diez pantallas de catálogos.
- #1799/PR #1809 incorporó el test HTTP Symfony de los diez endpoints. Su evidencia documenta aislamiento y consultas sintéticas, **no** recorrido Chromium.
- #1553/PR #1780 ya consolidó la matriz. Se mantienen S02–S11 en `NO_VERIFICABLE` hasta evidencias específicas completas.

## Contrato HTTP real de las vistas de prueba

Los diez catálogos son únicamente de lectura. Rutas de ámbito `/api/v1/branches/{branchId}/hr/{segmento}?company_id={companyId}`. El servidor, no el navegador, verifica el tenant del usuario, la entidad legal de la sede, `users.view` y el modo sintético. Con `HR_REAL_DATA_ENABLED=true` o fuera de su entorno dev/test autorizado, los catálogos deben devolver 404, no datos de empleados.

| Submódulo | Segmento | Campo de lista | Recorrido Chromium pendiente |
| --- | --- | --- | --- |
| S02 · cargos | `positions` | `positions` | carga, vacío, error, denegación, detalle sin mezcla de sedes |
| S03 · áreas | `areas` | `areas` | mismos cinco casos |
| S04 · sedes | `branch-preview` | `branches` | mismos cinco casos |
| S05 · organigrama | `org-chart` | `nodes` | mismos cinco casos |
| S06 · novedades | `changes` | `changes` | mismos cinco casos |
| S07 · permisos | `permits` | `permits` | mismos cinco casos |
| S08 · vacaciones | `vacations` | `vacations` | mismos cinco casos |
| S09 · incapacidades | `incapacities` | `incapacities` | mismos cinco casos |
| S10 · evaluaciones | `evaluations` | `evaluations` | mismos cinco casos |
| S11 · portal ficticio | `employee-portal` | `items` | mismos cinco casos |

Para cada lista, exigir `synthetic=true`, `persisted=false`, `juridically_binding=false`, items con `synthetic=true` y referencias opacas; nunca confiar en HTTP 200 sin validar su esquema. Las peticiones se realizan con `credentials: same-origin`, `cache: no-store` y sin almacenamiento local.

## Evidencia pendiente antes del cierre

1. Vista de los diez catálogos montada efectivamente en la aplicación Admin de prueba mediante opt-in **deshabilitado por defecto**, sin habilitar HR real.
2. Playwright Chromium sobre el bundle Vite compilado: permisos correctos, vacío, error, carga y 403/404 fail-closed por catálogo, al menos dos contextos ficticios sin cruces. Sin guardar fixtures que representen personas reales.
3. CI `Validar`, TypeScript/build, PHP, Playwright, SonarCloud y CodeQL exact-HEAD terminales; CodeRabbit FINAL una sola vez conforme a PLAN-AGENTES.
4. Un PR propio fusionado, con su UUID V3, y solo entonces reconciliación de la matriz por el titular autorizado.

La entrega sintética **no resuelve** la puerta jurídica B, #1497, persistencia laboral real ni los AC integrales del padre #1567.