# Condor #1529 · GEST-M04 — verificación de Nómina (9 submódulos)

**Corte:** 5 de noviembre de 2026 · rama `trabajo/issue-1529` · [PR #1635](https://github.com/pl0n3r/Condor/pull/1635). **Solo construcción sintética**, bandera RR. HH. apagada para datos reales por decisión B de [#742](https://github.com/pl0n3r/Condor/issues/742).

## Criterio y alcance

No se sustituye ni edita `docs/matriz-cobertura.md`: `NO_VERIFICABLE` significa falta de prueba integral, no ausencia automática del código. La siguiente tabla identifica **testigos ejecutables en el código real**, pero su resultado solo acredita subcontratos offline con entradas inventadas. El test nuevo es `tests/php/Application/Payroll/GestM04VerificationTest.php` (suite PHPUnit vía `vendor/bin/simple-phpunit`). No hay aprobación laboral, liquidación reglamentaria, pagos, PILA, DIAN ni publicación.

| ID | Submódulo | Código y prueba exacta | Resultado de verificación y límite |
| --- | --- | --- | --- |
| `GEST-M04-S01` | Periodos | `PayrollPeriodsProjection::project` · `testS01PeriodosValidadosSinCerrarNomina` | **Testigo sintético:** fechas y duplicados, estado requiere revisión. Falta calendario/periodo de nómina real. |
| `GEST-M04-S02` | Conceptos versionados | `PayrollPeriodsProjection::project` · `testS02ConceptosVersionadosConDecimalesExactos` | **Testigo sintético:** versión tipada, decimal exacto, rechazo de formato inválido. No hay devengo legal oficial. |
| `GEST-M04-S03` | Novedades | `PayrollPeriodsProjection::project` · `testS03NovedadesExigenReferenciasLocalesExistentes` | **Testigo sintético:** referencia a periodo/concepto existente; rechaza orfandad. No acredita integración con asistencia. |
| `GEST-M04-S04` | Horas | `PayrollPeriodsProjection::project` · `testS04HorasRechazanPeriodosAjenosYPrecisionInvalida` | **Testigo sintético:** precisión y periodo existente. No liquida horas trabajadas reales. |
| `GEST-M04-S05` | Recargos | `PayrollSurchargesBenefits::project` · `testS05RecargosCalculadosSoloConParametrosFicticios` | **Testigo sintético:** cálculo con tasas parametrizadas de prueba, no recargos jurídicos aplicables. |
| `GEST-M04-S06` | Deducciones | `PayrollSurchargesBenefits::project` · `testS06DeduccionesSinLimitesLegalesInventados` | **Testigo sintético:** cálculo numérico y rechazo de tasas inválidas; no valida topes legales. |
| `GEST-M04-S07` | Prestaciones | `PayrollSurchargesBenefits::project` · `testS07PrestacionesSinPretensionDeLiquidacion` | **Testigo sintético:** cálculo etiquetado `REQUIERE_REVISION`; no existe liquidación legal integral. |
| `GEST-M04-S08` | Desprendibles | `PayrollSurchargesBenefits::project` · `testS08DesprendibleSoloVistaNoOficial` | **Testigo sintético:** vista con `official=false` y marca `NO OFICIAL - DATOS SINTETICOS`. No hay desprendible certificado. |
| `GEST-M04-S09` | Salida electrónica cuando aplique | `PayrollController` · `testS09NoExisteEmisionElectronicaActivada` | **BRECHA ABIERTA:** solo negativa de no activación, sin emisión ni acuse DIAN. Hoja hija [#1634](https://github.com/pl0n3r/Condor/issues/1634), subordinada a #1529 y a la decisión jurídica de #742. |

## Aislamiento, permisos, HTTP y UI

- `testAislamientoTenantYEntidadLegalFallaCerrado` comprueba que filas de otro tenant o entidad contaminante invalidan **toda** la proyección, sin exponer registros parciales ni PII. `testEntradaDeBeneficiosNoAdmitePIINiEmpresaSinFixture` impone empresa ficticia y claves cerradas.
- `PayrollController` rechaza accesos fuera de `test` o `dev` con `CONDOR_PAYROLL_SYNTHETIC_API=1` **y** `CONDOR_PRODUCTION_STAGE=construccion`; exige `Membership::ROLE_OWNER`, alcance de tenant/empresa en `authorizedBranchScope`, permiso y CSRF en mutaciones. Testigo adicional existente: `tests/test_payroll_http_api.py` y `tests/test_payroll_persistence.py` (aislamiento, denegación, CAS, auditoría).
- Interfaz ya existente `frontend/admin/PayrollManagement.tsx`: `DEV` únicamente, solo memoria, sin llamadas asíncronas ni persistencia. `tests/test_payroll_admin_ui.py` verifica permisos, selección por empresa/sede, estado vacío, mensajes de rechazo/error y aviso no oficial. Un estado de carga remoto **no aplica** a esta UI local sin red; no se afirma prueba HTTP/E2E de una pantalla productiva.
- S01–S08 tienen prueba sobre PHP real de **su slice offline**, no son cobertura legal integral de M04. No promover filas a `CUBIERTO` en la matriz hasta evidencia completa, validación autorizada y consolidación por la hoja propietaria. **S09 sigue pendiente** incluso si este PR pasa `Validar`.

## Riesgo, ejecución y reversión

`vendor/bin/simple-phpunit tests/php/Application/Payroll/GestM04VerificationTest.php`; CI canónico `CI Factory v1 / Validar`, PHPStan, Rector, SonarCloud, CodeQL y revisión CodeRabbit FINAL sobre HEAD exacto. Riesgo bajo–medio, fixtures sin nombres, documentos, correos ni datos reales. Sin tocar proveedores, credenciales, SQL, frontend, otras reservas, datos reales o `docs/matriz-cobertura.md`. Reversión: revert del squash de [PR #1635](https://github.com/pl0n3r/Condor/pull/1635).