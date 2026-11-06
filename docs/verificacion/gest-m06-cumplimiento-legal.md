# Condor · GEST-M06 · verificación de Cumplimiento legal (#1531)

Corte: 2026-11-05, `main@e71d7dce52d9db39ef880af925189c392f61dd95` al iniciar.
**Fase construcción, sin activación operativa**. Pruebas sobre datos sintéticos
descartables; no son dictamen jurídico, certificación de conformidad, aprobación
profesional, datos de empresas reales ni prueba de producción. La regla #742/B,
D-063, revisión profesional, D-059 y el go-live permanecen independientes.

## Trazabilidad de las seis filas solicitadas

Testigo ejecutable: `tests/php/Application/Compliance/LegalCompliance/GestM06VerificationTest.php`.
Se ejecutan clases PHP reales, no dobles ni respuestas prefabricadas. Los
enlaces al PR acreditan únicamente la fusión del testigo una vez confirmada
por GitHub; un CI verde no acredita el flujo completo.

| Fila | Implementación encontrada en main | Aserción exacta añadida | Resultado y brecha |
| --- | --- | --- | --- |
| `GEST-M06-S01` · perfil legal | `NormativeApplicability::evaluate` + contexto de tenant/municipio F0 | `testS01PerfilLegalSinteticoFallaCerrado`: perfil ficticio, tenant ajeno y revisión profesional ausente | **PARCIAL / NO_VERIFICABLE integral**; sin CRUD HTTP autenticado de perfil legal real |
| `GEST-M06-S02` · catálogo normativo | `NormativeMatrix::evaluate` F1a con fuente, versión y vigencia | `testS02CatalogoNormativoNoFiltraProcedenciaAjena`: fuente sintética y aislamiento de tenant | **PARCIAL / NO_VERIFICABLE integral**; sin catálogo versionado accesible por interfaz autenticada |
| `GEST-M06-S03` · matriz de obligaciones y prohibiciones | `NormativeApplicability` + `NormativeMatrix` | `testS03MatrizObligacionesYProhibicionesSinDecisionAutomatica`: PROHIBIDO, NO_APLICA y dato inválido REQUIERE_REVISION | **PARCIAL / NO_VERIFICABLE integral**; no hay workflow HTTP/UI verificable por cada obligación |
| `GEST-M06-S04` · calendario | `NormativeReviewProjection` F1b + `ComplianceAttentionProjection` F2a | `testS04CalendarioSoloSugiereAtencionFicticia`: plazo trazado, atención no enviada, plazo de tenant ajeno rechazado | **PARCIAL / NO_VERIFICABLE integral**; no calendario HTTP/UI con permisos y estados completos |
| `GEST-M06-S06` · evaluaciones | `ComplianceEvidenceEvaluation::project` y API de evidencias genéricas `ComplianceApiService` | `testS06EvaluacionesSinteticasExigenRevisionHumana`: SIN_EVIDENCIA, aislamiento por tenant, datos no ficticios rechazados, sin aprobación automática | **PARCIAL / NO_VERIFICABLE integral**; API genérica no acredita evaluación por obligación en flujo HTTP/UI |
| `GEST-M06-S07` · auditorías | `ComplianceAuditCandidateProjection::project` F2b | `testS07AuditoriasSoloCreanCandidatosSinCierre`: candidato, tenant ajeno y ausencia de cierre | **PARCIAL / NO_VERIFICABLE integral**; falta ruta/UI auditada de plan de mejora y cierre humano |

La fila S05 no fue pedida por #1531 y no se promueve aquí.
**0 de 6 filas** se califican como `CUBIERTO` integral por estos testigos.

La prueba adicional `testS06ServicioGenericoRestringeTenantYNoAprueba` ejecuta
`ComplianceApiService` PHP real: listado y vista cross-tenant vacíos, cambios
en otro scope rechazados, y estados `APROBADO`/`CUMPLE`/`CERRADO`
prohibidos. Esto no sustituye autenticación HTTP ni auditoría real.

## Aislamiento, permisos, UI y fronteras del test

`testApiActualExigeScopePermisosCsrfYBanderaSintetica` inspecciona el
contrato fuente de `ComplianceController`: cuatro rutas genéricas
`/api/v1/branches/{branchId}/compliance/evidence`, scopes autorizados por
sesión, permisos `settings.view/create/update`, CSRF en mutación y flags
`CONDOR_COMPLIANCE_SYNTHETIC_API` / `CONDOR_PRODUCTION_STAGE`.
**No es una petición HTTP autenticada**. Los tests de proyecciones prueban
aislamiento lógico de fixture, no permisos reales de servidor por los seis
submódulos. La UI `ComplianceManagement.tsx` contiene un panel de evidencias
DEV, pero no acredita loading/empty/error/permission-denied para cada fila.

La hoja hija [#1629](https://github.com/pl0n3r/Condor/issues/1629),
dependiente de #1531, queda creada para completar integración HTTP, tenant,
RBAC, CSRF, bandera OFF, estados UX y pruebas E2E bajo **nueva V3** disjunta.
No se cambia `docs/matriz-cobertura.md` desde esta reserva, ni se repite el
módulo de evidencias ya implementado.

## Aceptación y control de calidad

- AC-01: seis testigos reales y una brecha descendiente explícita por fila.
- AC-02: pruebas negativas de aislamiento sintético; HTTP/UX completos **pendientes en #1629**.
- AC-03: sin PII, dinero, proveedor, redes, emisión, despliegue ni datos reales.
- AC-04: exige `CI Factory v1 / Validar` terminal `success` sobre el HEAD
  definitivo, además de calidad SonarCloud, CodeQL y CodeRabbit FINAL.
- Prueba local/canónica: `vendor/bin/simple-phpunit --filter GestM06VerificationTest`
  y suites CI vigentes; no afirmar éxito sin el recibo real de GitHub.

Reversión: squash revert del PR. Ninguna de estas simulaciones habilita
aprobar reglas, presentar cumplimiento legal, cerrar auditorías o activar
canales productivos.
