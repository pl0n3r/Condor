# Hoja GEST-FLUJO-2-P02/P03 · cadena documental DBAL sintética (#1927)

> **Estado:** subcontrato de código, pendiente de recibo terminal del PR en el primer corte. No es certificación jurídica, E2E HTTP→persistencia, validación productiva ni activación de RR. HH. La auditoría de #1621 conserva **41 `NO_VERIFICABLE`** integralmente.

## Fuente, ejecución y alcance

- Fuente canónica: `src/Domain/Documents/Entity/DocumentRecord.php` (versiones inmutables, `previous_id`, huella SHA-256), `src/Infrastructure/Persistence/DocumentRecordRepository.php` (append transaccional, roles, validación de huella, auditoría de lectura), migración aditiva `migrations/Version20261104120000.php`.
- Prueba nueva: `tests/php/Http/GestDocumentRecordSqlTest.php`, PHPUnit con kernel Symfony y Doctrine DBAL reales. Exige driver **`pdo_mysql`**, esquema migrado y **MariaDB descartable**; ausencia de conexión/tabla NO produce `skip` ni PASS simulado.
- CI esperado: job `Backend PHP / MariaDB` de `CI Condor`, con `vendor/bin/simple-phpunit` y tests PHP. El recibo suficiente exige URL del run, HEAD SHA, paso que ejecutó la clase, `CI Factory v1 / Validar` y check agregado `Validar` terminales verdes sobre ese **mismo HEAD**. Registrar esa evidencia en el PR una vez observada; este texto solo enumera el contrato.
- Reversión: revertir el squash que añade únicamente esta prueba y este informe. Los datos de cada test son referencias ficticias `fixture:...` y la transacción se revierte en `finally`, incluso al fallar una aserción.

## Conducta negativa y positiva reproducible

| Obligación | Método concreto y resultado exigido | Límite |
| --- | --- | --- |
| Versionado DBAL por empresa | `testMariaDbVersionChainAuditAndRollback`: versión 1 `BORRADOR` → versión 2 `REQUIERE_REVISION`, `previous_id` al ID original, huellas diferentes e inmutables y tres filas de auditoría (dos escrituras y una lectura) | No existe archivo de contenido ni conexión al controlador HTTP M08 |
| Aislamiento por tenant y entidad | Mismo test crea registros separados para `fixture:tenant/alpha` y `fixture:tenant/beta`; `forCompany` devuelve exclusivamente su empresa | No demuestra todos los roles/sedes de la UI |
| Roles, revisión separada y orden | `testStaleCrossTenantRoleAndSelfReviewFailClosed`: rechaza auto-revisión, aprobación implícita, evento v2 antes de v1, duplicado v1, scope distinto, rol de solo lectura al escribir, `authorized=false` y referencias no ficticias | `REQUIERE_REVISION` nunca se reinterpreta como `CUMPLE` o `APROBADO` |
| Huella en base real | `testPersistedFingerprintTamperingIsRejected`: modifica `fingerprint` dentro de transacción descartable y exige `DomainException` en lectura | SHA-256 sobre metadatos, **no** sobre un binario cargado ni evidencia de firma legal |
| Rollback seguro | Cada prueba revierte SQL y comprueba ausencia del registro centinela | No acredita restauración productiva ni RPO/RTO |

## Estado integral y puertas conservadas

| Fila | Testigo nuevo | Estado integral |
| --- | --- | --- |
| `GEST-FLUJO-2` | Versiones de metadatos en DBAL con revisor diferente y huellas | **NO_VERIFICABLE**: falta recorrido conectado de clasificación→carga de archivo→revisión competente→retención/legal hold→exportación |
| `GEST-FLUJO-2-P02` | Versión 1→2 persistida, orden y detección de alteración de metadatos | **NO_VERIFICABLE**: falta integridad verificable del archivo y vinculación HTTP/usuario/actor real |
| `GEST-FLUJO-2-P03` | Revisor técnico distinto para solicitar revisión; sin cierre automático | **NO_VERIFICABLE**: falta aprobar/rechazar/solicitar cambio por revisor legal autorizado |
| `GEST-FLUJO-2-P04` | Sin operaciones de eliminación en la prueba | **NO_VERIFICABLE**: legal hold y política de retención ejecutables no existen en esta cadena |

**Invariantes:** `docs/matriz-cobertura.md` y `docs/gestion-empresarial-integral.md` son ajenos a la reserva; no se tocan. La decisión #742/B y D-059/D-063 mantienen F3/F4 y RR. HH. real OFF. No se realizan peticiones externas, migraciones, datos reales, cambios de hosting o go-live. El cierre de #1621 exige pruebas por fila y autoridad jurídica de las obligaciones que aún lo requieran.
