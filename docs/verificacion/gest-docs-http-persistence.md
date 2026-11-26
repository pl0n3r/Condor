# GEST-DOCS HTTP → MariaDB: primer borrador sintético (#2015)

**Ámbito:** Condor en `construccion` (dev/test), sin documentos reales, PII, firma, aprobación jurídica, retención, legal hold, binarios, secretos ni cambio de proveedor. El gate `constructionOnly()` devuelve 404 en producción.

## Fuente de verdad

- `POST /api/v1/branches/{branchId}/documents` valida CSRF `branch_access`, permiso `settings.create`, JSON cerrado (`reference` y `classification`) y referencia `fixture:doc/<key>`; guarda **una** fila versionada (`BORRADOR`, v1) y su audit `CREADA` mediante `BranchDocumentRecordRepository::createDraft`, transacción incluida.
- `GET /api/v1/branches/{branchId}/documents` y `GET .../{documentKey}` validan `settings.view` y usan exclusivamente `forBranch` sobre el mismo vínculo por tenant, entidad y sede, incluso tras nueva sesión. Respuesta incluye `id` y `version` SQL verificables.
- Los IDs de tenant/entidad/sede/actor proceden de `authorizedBranchScope()` y objetos Doctrine del servidor. `DocumentDraftPersistence::fixture()` convierte el ULID Crockford 26-char inyectivamente a una referencia sintética con alfabeto permitido y separadores, sin truncación ni hashes que puedan colisionar. `branchAuthorized=true` y rol `editor/auditor` solo son derivados tras RBAC servidor. Ningún ID, permiso, actor o scope del body.
- `fixture:doc/<key>` se enlaza únicamente a `fixture:dossier/<key>` externo, mientras SQL genera su dossier interno aleatorio e inmutable. La restricción única de `condor_document_branch_link` impide replay concurrente. HTTP devuelve **409** ante duplicado, sin segunda fila/version ni auditoría de escritura parcial.
- `trace`, `export`, `evidence` y `request-review` están fuera de esta hoja: tras autenticación/validación aplicable devuelven **409** para registros persistentes y nunca escriben sesión ni anuncian transición o exportación finalizada. El segundo slice debe integrarlos con repositorio versionado y auditoría antes de habilitarlos.

## Cobertura verificable

- `tests/php/Http/GestDocumentDraftPersistenceHttpTest.php`: Symfony firewall/CSRF, MariaDB descartable, `POST` + `GET` tras `restart()`, mismo `id/version` en SQL, otro tenant/entidad/sede 404, payload/actor ajeno 422, permiso insuficiente 403, replay 409 y auditoría `CREADA` única. Usa rollback de fixture.
- `tests/php/Http/DocumentsControllerHttpTest.php`: regresión de rutas, validación, autorización, gate jurídico y fallos 409 explícitos de operaciones no persistidas.
- Complemento upstream: `GestDocumentRecordSqlTest` (#1927) y `GestDocumentBranchScopeSqlTest` (#2019) prueban integridad/fingerprint, FK compuesto, scope SQL y rollback. No sustituir pruebas HTTP por tests aislados.

## Puerta pendiente y reversión

Este es **build-ahead**, NO despliegue: bajo Factory#912 y D-043, `workflow success` no acredita `VALIDATED_IN_PRODUCTION`; sin evidencia exacta de observer/identidad y sin puertas de datos aplicables, **no se fusiona ni activa**. D-063: `datos.yml` conserva `phase=construccion`, `nothing_live=true` y `no_real_customer_data=true`. Este cambio no introduce datos reales/PII ni altera la finalidad de tratamiento. Las operaciones de revisión, evidencia, exportación, firma y retención permanecen negadas.

Reversión controlada: revertir el squash **solo después de una eventual fusión autorizada**; no se modifica esquema, migraciones ni versión de producto en esta preparación.
