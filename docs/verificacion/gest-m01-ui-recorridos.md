# M01 S02–S11: recorridos visuales sintéticos (#1817)

**Estado: REGRESIÓN SINTÉTICA VERIFICADA EN CI; NO VALIDADO EN PRODUCCIÓN.** Esta entrega acredita una UI de prueba, no el tratamiento legal de datos laborales ni toda la aceptación integral de #1567. La reconciliación de la matriz sigue siendo responsabilidad de su titular, con evidencia de sus otras dimensiones.

## Autoridad y antecedentes

- Condor #742 mantiene elección humana **B**; RR. HH. y asistencia con datos reales permanecen deshabilitados. #1497 carece de aprobación jurídica profesional.
- #1651/PR #1777 introdujo la pantalla genérica de registros; #1799/PR #1809 cubrió la frontera HTTP Symfony sintética de los diez catálogos. Son precondiciones ya fusionadas.
- #1553/PR #1780 construyó la matriz. No promover sus S02–S11 desde `NO_VERIFICABLE` únicamente por esta regresión UI: la tríada completa y la decisión jurídica permanecen pendientes.

## Recorridos comprobados

Cada consulta de prueba usa `GET /api/v1/branches/{branchId}/hr/{segmento}?company_id={companyId}`, con `credentials: same-origin` y `cache: no-store`. El servidor conserva la autoridad final sobre tenant, empresa, sede, `users.view` y entorno. El navegador rechaza respuestas sin `synthetic=true`, `persisted=false`, `juridically_binding=false`, filas sintéticas o referencias opacas válidas.

| Submódulo | Segmento | Campo de respuesta | Regresión Chromium sintética |
| --- | --- | --- | --- |
| S02 · Cargos | `positions` | `positions` | carga, dato ficticio, vacío, error, 403, ámbito ajeno |
| S03 · Áreas | `areas` | `areas` | mismos seis escenarios |
| S04 · Sedes | `branch-preview` | `branches` | mismos seis escenarios |
| S05 · Organigrama | `org-chart` | `nodes` | mismos seis escenarios |
| S06 · Novedades | `changes` | `changes` | mismos seis escenarios |
| S07 · Permisos | `permits` | `permits` | mismos seis escenarios |
| S08 · Vacaciones | `vacations` | `vacations` | mismos seis escenarios |
| S09 · Incapacidades | `incapacities` | `incapacities` | mismos seis escenarios |
| S10 · Evaluaciones | `evaluations` | `evaluations` | mismos seis escenarios |
| S11 · Portal ficticio | `employee-portal` | `items` | mismos seis escenarios |

`tests/e2e/hr-m01-synthetic-catalogs.spec.mjs` monta **los componentes React/TSX reales** mediante Vite en un navegador Chromium de pruebas, intercepta exclusivamente HTTP ficticio y exige los estados de cada módulo, negativas sin permiso, ausencia de HTTP sin opt-in y cambio entre **dos empresas/sedes ficticias** sin persistencia de filas del contexto anterior. Las respuestas 401/403 son denegadas; 404 deshabilita la vista, y 500/payload inválido/ámbito ajeno fallan cerrado. No se crean personas ni relaciones laborales reales.

El acceso está limitado a `import.meta.env.DEV` y al opt-in explícito `data-hr-synthetic-preview-enabled=true` con `HR_REAL_DATA_ENABLED` apagado. Sin opt-in no aparece la vista ni se realizan consultas laborales. El bundle de producción no habilita el módulo sintético; la compilación Vite de CI mostró **`Build ya reconciliado; no hay commit generado`**, por lo que `public/build/admin.js` no requirió cambio en el diff. Esto no equivale a probar la interfaz sobre un despliegue productivo.

## Evidencia verificable y límite de aceptación

- [PR #1818](https://github.com/pl0n3r/Condor/pull/1818): implementación de componentes, opt-in y pruebas sobre rutas declaradas de la reserva V3 original.
- [CI Condor #42357892267, intento 2](https://github.com/pl0n3r/Condor/actions/runs/42357892267): `Frontend TypeScript / build`, `Playwright Chromium`, `Backend PHP / MariaDB`, `Validar coordinación`, `CI Factory v1 / Validar` y `Validar` terminales `success` sobre el HEAD probado `bafda562b8b79523a430ad4d489f45bcb00ee5c6`. La modificación de este documento requiere **validación de nuevo HEAD** antes de fusionar; no transportar el verde por inferencia.
- SonarCloud y CodeQL tienen checks terminales `success` en el SHA probado. La revisión **CodeRabbit FINAL única** solo se solicita después de estabilizar el HEAD definitivo y obtener su CI verde.
- La matriz del padre #1567 se reconcilia exclusivamente tras el squash y la evidencia de su titular. Ningún check concede permiso para desplegar, gestionar PII, activar `HR_REAL_DATA_ENABLED` ni declarar conformidad jurídica.

**Reversión:** squash reversible de un módulo dev/test; no se han emitido órdenes, permisos, secretos, cambios reales de datos ni operaciones live.
