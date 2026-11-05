# M11 — procesos, procedimientos y tareas (S01–S03)
Hoja [#1575](https://github.com/pl0n3r/Condor/issues/1575), hermana #1576; origen #1536, fase **construcción**. Modo `dev/test` únicamente, sin datos reales, ejecución, persistencia, envío, migraciones ni ampliación de permisos.
| ID | Alcance verificable de este slice | Límite real |
| --- | --- | --- |
| GEST-M11-S01 | Catálogo estructurado `fixture:process/recepcion` por API con sede autorizada. | No catálogo real ni automatización de procesos. |
| GEST-M11-S02 | Procedimiento `fixture:procedure/inspeccion` anidado y vinculado al proceso. | No versionado operativo ni ejecución de procedimientos. |
| GEST-M11-S03 | Dos tareas `fixture:task/*` con estados de ensayo `pending/done` validados por POST. | No se asignan, ejecutan ni guardan tareas reales. |
`OperationsProcessControllerTest` prueba respuestas HTTP, CSRF, permiso, 404 cross-tenant, 422 por flag inactivo, referencias cruzadas/payload extra y `persisted=false / dispatched=false`; frontend en modo DEV ofrece loading, empty, error y permission-denied. GET requiere `inventory.view`; POST `inventory.update`; además se exige el add-on `production-lite` del tenant. En `prod` ambas rutas fallan cerrado con 404.

**Veredicto:** slice de ensayo S01–S03 verificable; cobertura integral operacional **NO_VERIFICABLE** hasta implementar datos/recorridos reales con permisos y negativos. No modificar `docs/matriz-cobertura.md` (lo consolida #1553), ni declarar producción validada por CI.
