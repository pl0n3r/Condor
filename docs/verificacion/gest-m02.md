# Gestión empresarial M02 · evidencia sintética (#1527)

**Corte:** fase `construccion`, sin PII ni firmas laborales reales. El código existente es `ContractTemplatesProjection` (S01–S03), `ContractLifecycleProjection` (S04–S08), `ContractApiService` y `ContractsController`. Esta hoja agrega testigos ejecutables, no certifica legalidad ni operación. Antecedente: [PR #1375](https://github.com/pl0n3r/Condor/pull/1375). PR de esta tanda: vinculado a #1527; solo GitHub/CI exact-HEAD prueban su fusión.

| Fila | Submódulo | Testigo PHP real en `GestM02PreviewTest` | Estado integral y brecha | Hija |
| --- | --- | --- | --- | --- |
| GEST-M02-S01 | Plantillas revisables | `testS01PlantillaVersionadaRequiereRevision` + `ContractTemplatesProjection` | **NO_VERIFICABLE**: versión/revisión offline, sin plantilla jurídica aprobada, UI conectada ni permiso HTTP E2E | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S02 | Contratos | `testS02ContratoRequiereVersionDePlantillaYNoValidaFirma` + `ContractTemplatesProjection` | **NO_VERIFICABLE**: vínculo ficticio plantilla/contrato, sin expediente laboral persistente ni firma válida | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S03 | Otrosíes | `testS03OtrosiRechazaContratoAjenoYMotivoInvalido` + `ContractTemplatesProjection` | **NO_VERIFICABLE**: validación de referencia/motivo offline, sin autorización por rol ni workflow de cambios reales | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S04 | Firmas/evidencias | `testS04EvidenciaFicticiaNoCertificaFirma` + `ContractLifecycleProjection` | **NO_VERIFICABLE**: evidencia ficticia no certifica firma, no hay endpoint de firma ni proveedor autorizado | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S05 | Renovaciones | `testS05RenovacionSoloProponeYRechazaFechaAnterior` + `ContractLifecycleProjection` | **NO_VERIFICABLE**: propuesta sin aplicación ni persistencia, sin autorización real | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S06 | Alertas | `testS06AlertaEsCandidataYNoNotificacionReal` + `ContractLifecycleProjection` | **NO_VERIFICABLE**: candidato según fecha, no hay canal de notificación real ni evidencia de entrega | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S07 | Terminación | `testS07TerminacionExigeMotivoYNoCierraContrato` + `ContractLifecycleProjection` | **NO_VERIFICABLE**: borrador sin cierre, revisión jurídica y rol autorizador pendientes | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |
| GEST-M02-S08 | Liquidación | `testS08LiquidacionNoCalculaNiApruebaValoresLegales` + `ContractLifecycleProjection` | **NO_VERIFICABLE**: sin importe/COP, aprobación, cálculo jurídico, expediente ni UI | [#1577](https://github.com/pl0n3r/Condor/issues/1577) |

## Frontera HTTP y seguridad

La API existente `GET/POST /api/v1/branches/{branchId}/contracts` y `GET /api/v1/branches/{branchId}/contracts/{kind}/{slug}`/`PATCH .../state` solo maneja `template|contract|addendum` ficticios en sesión, bajo `authorizedBranchScope` (permiso `inventory.view/create/update`, tenant + entidad legal + sede), CSRF para mutaciones y `requireSyntheticEnvironment`. `testApiAislaAmbitosYRestringeEstados` prueba separación de almacenes y estados; `testApiRechazaIdentificadoresRealesYSobrecargaDeCampos` rechaza campos extra; `testRechazaTenantCruzadoYCargaConPii` prueba entrada de otro tenant y PII en proyecciones. `testControllerMantieneFronteraSinteticaYAutorizacion` solo contrasta la presencia de guards en código, **no** su efectividad HTTP E2E.

**Brecha común #1577:** faltan pruebas HTTP autenticadas cross-tenant/permiso-denegado, UI real con loading/empty/error/permission-denied, y rutas S04–S08; sin ellas ninguna fila integral puede pasar a `CUBIERTO`. La matriz `docs/matriz-cobertura.md` queda intacta, reservada a la hoja de consolidación. #742 mantiene la opción B: RR. HH. apagado para datos reales; faltan decisión OWNER y D-063 auténticos antes de cualquier tratamiento de PII o live.

**Verificación:** `vendor/bin/phpunit tests/php/Application/Contracts/GestM02PreviewTest.php`, más gates de `CI Factory v1 / Validar` en HEAD exacto. Sin green exacto no se afirma aceptación. Reversión: revert del squash; no hay migración, proveedores ni estado productivo.
