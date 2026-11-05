# Condor · gestión documental M08 — verificación #1533

Corte: 2026-11-05. Solo `construccion` con datos `fixture:*`; sin firma electrónica real, proveedor, PII, persistencia productiva ni validación jurídica. No modificar `docs/matriz-cobertura.md` hasta consolidación. La evidencia de este PR no puede citarse como **PR fusionado** antes del squash.

## Contraste por submódulo

| ID | Código y aserción de regresión | Cobertura limitada y brecha vigente |
| --- | --- | --- |
| GEST-M08-S05 (firma/evidencia) | `DocumentApiService::attachEvidence` y `DocumentsController::attachEvidence`; `GestM08VerificationTest::testS05EvidenceCannotBecomeLegalSignatureOrCrossTenantWrite`. Solo `fixture:evidence/*`; RBAC `settings.update`, CSRF, fase dev/test, idempotencia e inmutabilidad de evidencia. | **PARCIAL / NO_VERIFICABLE integral:** referencia ficticia, no firma electrónica, identidad real, certificado, integridad probatoria ni flujo UI. El API declara `legal_signature=false`. |
| GEST-M08-S07 (exportación) | `DocumentApiService::export`, `DocumentsController::export`; `testS07ExportIsDeterministicMetadataOnlyAndTenantScoped`. Entrega descarga JSON (`attachment`) de metadatos sintéticos, ordenados y aislados por ámbito; `Cache-Control: private, no-store` y RBAC `settings.view`. | **PARCIAL / NO_VERIFICABLE integral:** JSON de prueba; no exportación firmada, PDF, descarga de adjuntos, persistencia ni UI. |
| GEST-M08-S08 (trazabilidad) | `DocumentApiService::trace`, `DocumentsController::trace`; `testS08TraceIsAppendOnlyAndScopedToDocument`. Registra secuencia de creación, evidencia y solicitud de revisión dentro de la sesión sintética; ámbito por tenant/entidad/sede; RBAC `settings.view`. | **PARCIAL / NO_VERIFICABLE integral:** historial de sesión no equivale a log de auditoría inmutable, actor autenticado retenido, persistencia o recuperación histórica. El controller genera audit events separados para accesos. |

## Seguridad, aceptación y límites

- El controlador comprueba `constructionOnly()` antes de acceder a sesión; el ámbito deriva de tenant/entidad legal/sede con hash y `authorizedBranchScope()` rechaza accesos sin permiso / cross-tenant. Mutaciones exigen CSRF. Las aserciones unitarias prueban aislamiento del servicio; el contrato de rutas es una lectura estructural, **no** sustituye HTTP autenticado real ni E2E.
- `DocumentApiService` rechaza referencias no sintéticas, cambios de evidencia, aprobación/cierre automático y exportaciones de otra sede. No registra identidad personal ni contenido binario.
- Faltan estados React `loading/empty/error/permission-denied`, prueba HTTP negativa real y firma verificable. Se desglosan en la hoja hija [#1571](https://github.com/pl0n3r/Condor/issues/1571), bloqueada hasta la fusión de #1533 y las autorizaciones de seguridad/jurídicas pertinentes; ninguna fila pasa a `CUBIERTO` integral antes de acreditar esa brecha.
- Reversión: squash revert. Los gates `CI Factory v1 / Validar`, SonarCloud, CodeQL y CodeRabbit FINAL se validan en el HEAD exacto; sin usar éxitos previos.
