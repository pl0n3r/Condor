# Condor · GEST-M03 · verificación SG-SST (Issue #1528)

Corte: 2026-11-05 · rama `trabajo/issue-1528`. **Solo pruebas sintéticas de
construcción.** Ninguna aserción de esta hoja acredita obligaciones SG-SST,
conformidad legal, registros laborales reales, despliegue ni autorización para
procesar personas. La puerta jurídica #742/B y el go-live independiente
permanecen cerrados. No se modifica `docs/matriz-cobertura.md`.

## Testigos exactos y brecha por cada submódulo

Prueba principal: `tests/php/Application/Compliance/GestM03VerificationTest.php`
(PHPUnit ejecuta servicios PHP reales; no reemplaza el HTTP de extremo a extremo).

| Fila | Contrato PHP existente | Aserción exacta añadida | Resultado y brecha |
| --- | --- | --- | --- |
| GEST-M03-S01 Perfil de estándares | `OccupationalSafetyPrecheckProjection::project` | `testS01PerfilNoAcreditaCumplimiento`: perfil ficticio aceptado en revisión; tenant ajeno y email rechazados | **PARCIAL**; no endpoint específico de perfil, no determinación legal |
| GEST-M03-S02 Matriz de peligros | `OccupationalHazardMatrix::project` | `testS02S03S04PeligrosControlesEInspeccionesAislados`: banda sintética 12, tenant/entidad ajenos vacíos | **PARCIAL**; proyección, no flujo HTTP/UI integral |
| GEST-M03-S03 Controles | `OccupationalHazardMatrix::project` | misma prueba: jerarquía INGENIERIA; APROBADO se rechaza | **PARCIAL**; no endpoint propio de controles |
| GEST-M03-S04 Inspecciones | `OccupationalHazardMatrix::project` | misma prueba: inspección PROGRAMADA y entidad ajena vacía | **PARCIAL**; no registro integral de inspección |
| GEST-M03-S05 Incidentes | `OccupationalIncidentInvestigation::project` | `testS05S06IncidenteEInvestigacionSinCierreAutomatico`: incidente ficticio, tenant ajeno vacío | **PARCIAL**; API genérica sin contrato integral de incidentes |
| GEST-M03-S06 Investigación | `OccupationalIncidentInvestigation::project` | misma prueba: plazo `FIXTURE_ONLY_NOT_LEGAL`, acciones pendientes, sin cierre | **PARCIAL**; no endpoint específico de investigación |
| GEST-M03-S07 EPP | `OccupationalPpeTraining::project` | `testS07S08S09S10ActividadesSinteticasYSinAprobacion`: entrega y reposición sin aprobación | **PARCIAL**; API genérica, sin seguimiento real de dotación |
| GEST-M03-S08 Capacitación | `OccupationalPpeTraining::project` | misma prueba: referencia de asistente solo `fixture:participant/*` y estado pendiente | **PARCIAL**; sin asistencia laboral real |
| GEST-M03-S09 Emergencias | `OccupationalPpeTraining::project` | misma prueba: simulacro ficticio con revisión humana | **PARCIAL**; sin plan de emergencias productivo |
| GEST-M03-S10 Comités/vigías | `OccupationalPpeTraining::project` | misma prueba: COMITE_VIGIA con resultado pendiente | **PARCIAL**; sin endpoint propio de comité/vigía |

## Frontera HTTP/UX y siguiente hoja

`testHttpServicioRestringeTenantYEstados` prueba el servicio real
`OccupationalApiService`: seis tipos genéricos, seis filas por tenant,
lista/vista cross-tenant vacía y mutación cross-tenant denegada.
`testControladorCierraPermisosBanderaYCrsf` comprueba las cuatro rutas
`/api/v1/branches/{branchId}/sgsst/records[/{slug}[/state]]`,
`authorizedBranchScope`, permisos `settings.*`, CSRF, gate
`CONDOR_OCCUPATIONAL_SYNTHETIC_API` + `CONDOR_PRODUCTION_STAGE`
y auditoría de servidor. **La prueba de controlador es inspección de contrato
fuente, no una petición HTTP autenticada.**

La vista `frontend/admin/ComplianceManagement.tsx` tiene una demo SG-SST
local `DEV` y una UI separada para evidencias con loading/error; eso **no**
prueba esos cuatro estados para los diez submódulos SG-SST sobre API real.
Brecha única y hoja hija: [#1561](https://github.com/pl0n3r/Condor/issues/1561),
que desarrollará solo slices ficticios de integración con permisos y estados
loading/empty/error/permission-denied (dependencia #1528, sin claims paralelos).

**Calificación:** diez testigos PHP de subcontratos parciales; **0 promociones**
a `CUBIERTO` integral. No confundir una suite verde con una validación
productiva, cumplimiento jurídico o permiso para quitar el gate de construcción.

## Verificación

`vendor/bin/phpunit --filter GestM03VerificationTest` y
`CI Factory v1 / Validar` sobre SHA exacto del PR; el estado final debe
provenir de GitHub, nunca presumirse por esta hoja.
