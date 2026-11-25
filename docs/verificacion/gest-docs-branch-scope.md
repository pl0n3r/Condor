# Contrato SQL de expedientes documentales por sede — Condor #2019

**Estado:** implementación para MariaDB descartable en desarrollo/tests; no conectada a HTTP ni a producción. Dependencia de #2015; no acredita persistencia HTTP, firma, validez legal, revisión ni go-live.

## Fuente única y ámbito

`BranchDocumentRecordRepository(Connection)` reutiliza `DocumentRecord` y `DocumentRecordRepository::append`. `createDraft(tenantRef, entityRef, branchRef, externalRef, versionRef, classification, actorRef, role, branchAuthorized)` solo admite referencias `fixture:*`; nunca recibe un `record_ref` interno del cliente. La identidad interna `fixture:dossier/<letras>` se genera con 128 bits aleatorios; la asociación inmutable por `(tenant, entidad, sede, referencia_externa)` se persiste con el borrador y la auditoría **en una única transacción**. El duplicado de clave externa falla sin alta/auditoría parcial.

`forBranch(tenantRef, entityRef, branchRef, actorRef, role, branchAuthorized)` ejecuta un **JOIN SQL delimitado** por las tres dimensiones antes de auditar cada versión; valida firmas SHA-256 del `DocumentRecord` histórico. Los registros antiguos sin asociación no aparecen ni generan auditorías de lectura. `forCompany()` legacy permanece independiente para fixtures históricos y no se usa como fuente de este adaptador.

La migración nueva únicamente crea `condor_document_branch_link` con FK compuesta a `(tenant_ref, legal_entity_ref, record_ref, version=1)`, unicidad también del dossier interno en su empresa y `ON DELETE/UPDATE RESTRICT`; no toca la migración M08. Su `down` aborta si existen vínculos. Nunca hacer backfill/inferir sede de registros históricos.

## Límite de confianza para #2015

**Solo el servidor** debe construir los parámetros desde tenant, entidad, sede y actor previamente validados por `BranchAuthorization`, más `branchAuthorized=true` únicamente tras permiso de sede comprobado. Ningún campo de scope/rol proviene del body HTTP, URL manipulable o sesión obsoleta. El adaptador no autentica usuarios; rechaza `false`, roles insuficientes y referencias no sintéticas. El controlador HTTP sigue usando sesión y **no debe usar este adaptador todavía**: #2015 debe resolver el paso seguro de IDs autenticados a fixtures dev/test, CSRF y errores 403/404, y ejecutar pruebas reales de ida/vuelta con nueva sesión antes de habilitarlo.

## Pruebas y reversión

`tests/php/Http/GestDocumentBranchScopeSqlTest.php`: MariaDB real con transacciones descartables para dos sedes hermanas, otra entidad/tenant, legado invisible, aislamiento de auditoría, duplicado, denegación, huella manipulada, FK contra scope cruzado y rollback conjunto. `Backend PHP / MariaDB`, `CI Factory v1 / Validar` y `Validar` deben evaluarse sobre SHA final; `php -l` no reemplaza esas pruebas. Reversión por revert del PR y `down` **solo si la tabla está vacía**, sin eliminar registros ni alterar datos reales.
