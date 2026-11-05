# Condor · GEST-M13-S03 · Calidad / quejas internas

Corte: 2026-11-05. Issue [#1538](https://github.com/pl0n3r/Condor/issues/1538), PR [#1585](https://github.com/pl0n3r/Condor/pull/1585) (fusión no presumida), rama `trabajo/issue-1538`, solo construcción y fixtures sintéticos. `docs/matriz-cobertura.md` se reserva a la consolidación.

| Fila | Código y aserciones exactas | Resultado / brecha restante |
| --- | --- | --- |
| `GEST-M13-S03` · quejas internas | `QualityApiService::createRecord` admite `QUEJA_INTERNA` solo con `fixture:quality-record/*` y `fixture:quality-source/*`. `QualityRecord::open` admite `queja_interna` y no aprueba/cierra. `GestM13VerificationTest::testS03QuejasInternasAisladasPorTenantYSede`, `testS03EntradasRealesYCamposExtraRechazados`, `testS03EntidadInmutableNoCertificaNiCierra`, `testS03ControladorConGuardasAutorizacionServidorYCsrf`. | **PARCIAL / NO_VERIFICABLE integral**: slice backend sintético verificable, pero sin flujo completo de atención, notificaciones, adjuntos, responsables ni UI/HTTP autenticados. |

## Controles existentes y alcance de la prueba

`QualityController` ya expone GET/POST/PATCH sobre `/api/v1/branches/{branchId}/quality/records`, usa `authorizedBranchScope`, permisos `settings.view/create/update`, CSRF para escrituras y auditoría del servidor. Aísla la sesión con hash de tenant + sucursal. No está disponible en producción; fuera de `test` requiere `dev`, `CONDOR_QUALITY_SYNTHETIC_API=1` y `CONDOR_PRODUCTION_STAGE=construccion`.

Los tests ejecutan el servicio y la entidad reales con datos ficticios y contrastan las guardas del controlador **por inspección de código**; **no** constituyen prueba de peticiones HTTP con sesión autenticada. `frontend/admin/QualityManagement.tsx` es solo un simulador local sin fetch: representa `empty` y `permission-denied` locales, pero **no** estados de red `loading` y `error` ni respuestas 403/404 reales. Brecha registrada en la hoja hija [#1582](https://github.com/pl0n3r/Condor/issues/1582), bloqueada hasta fusionar #1538 porque ese archivo frontend queda fuera de los claims de esta reserva.

## Verificación y reversión

- `vendor/bin/phpunit --filter GestM13VerificationTest`.
- `CI Factory v1 / Validar` debe terminar en `success` para el HEAD exacto antes de fusionar.
- Sin datos reales, proveedores externos, gasto, migraciones, deploy, go-live ni certificación. Reversión por revert del squash.
