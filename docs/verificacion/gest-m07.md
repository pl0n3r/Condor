# Condor · GEST-M07-S06 · verificación de cierres (Issue #1532)

Corte: 2026-11-05. **Solo construcción y datos sintéticos**, nunca cierre
contable, conciliación bancaria real, aprobación, movimiento de fondos o
certificación fiscal. No se modifica `docs/matriz-cobertura.md`.

| Fila | Código y ruta | Testigo exacto | Calificación y brecha |
| --- | --- | --- | --- |
| `GEST-M07-S06` · cierres | `FinanceReconciliationBudget::project` + `POST /api/v1/branches/{branchId}/finance/closures/preview` | `GestM07ClosureVerificationTest::testS06ConciliadoSoloEsCandidatoDeRevision`; `testS06SinEvidenciaOConciliacionNoHayCandidato`; `testS06RechazaTenantAjenoEstadosYDatosNoFicticios`; `testEndpointProtegidoYLimitadoAFixtures` | **PARCIAL / NO_VERIFICABLE integral**: candidato de revisión ficticio, jamás `can_close=true`; faltan HTTP funcional con identidad real y UI loading/empty/error/permission-denied |

## Aserciones y fronteras

- El servicio calcula candidatos solo con al menos un asiento del período,
  conciliación exacta y entradas ficticias; períodos vacíos nunca son candidatos.
- Tenant/empresa ajenos, payload adicional/PII, decimales no tipados y estado
  `CERRADO` devuelven proyección rechazada sin exposición de datos.
- La ruta está detrás de `FinanceController::access`: tenant y sede reales
  resueltos por servidor, permiso `settings.update`, CSRF, entorno de prueba
  o bandera explícita DEV y etapa `construccion`; no hay persistencia financiera.
- La identidad `fixture:tenant/current` / `fixture:legal-entity/current`
  sirve **solo al cálculo efímero**. No es un tenant real ni credencial de acceso.
  La verificación del controlador en esta tanda es una aserción de contrato
  fuente, **no** una prueba HTTP con sesión autenticada.
- La UI actual `frontend/admin/FinanceManagement.tsx` es memoria local;
  no consume el endpoint ni garantiza los cuatro estados frente a errores HTTP.
  Esa integración y negativas HTTP autenticadas requieren una hoja hija de
  frontend/QA en la [hoja hija #1568](https://github.com/pl0n3r/Condor/issues/1568),
  con claims separados, nunca ampliación de esta reserva.

Comprobación local: `vendor/bin/simple-phpunit --filter GestM07ClosureVerificationTest`.
Puerta de merge: `CI Factory v1 / Validar` terminal `success` del HEAD
exacto y demás condiciones de `PLAN-AGENTES.md`. Reversión: revert del squash.
