# Condor · inventario del drift Doctrine y grupos expand-compatibles (#1490)

**Base observada:** `main@0e610785455c72ad3fe837ce0dee684350ae5e19`, PHP 8.5, Doctrine ORM 3/DBAL 4 y MariaDB **13.0.2 temporal sin datos reales**. Fase `construccion`. Esta hoja **no autoriza** despliegue, go-live ni operación de esquema sobre clientes; mantiene D-054.

## Inventario reproducido y prioridades

Se construyó una base MariaDB efímera, se ejecutaron **53 migraciones existentes** (`doctrine:migrations:migrate --no-interaction`: success, 135 consultas SQL) y después se ejecutó `doctrine:schema:validate`: el mapeo ORM es correcto, pero **schema no sincronizado**. El comando de solo lectura `doctrine:schema:update --dump-sql` generó **349 sentencias** de drift; **nunca se ejecutó el SQL del dump**.

| Prioridad | Evidencia | Tratamiento |
| --- | --- | --- |
| P0 · prohibido automático | **21 `DROP TABLE`** (entre otras, `condor_document_audit`, `condor_controlbot_nonce`), **73 `DROP FOREIGN KEY`**, bajas de índices y restricciones: muchas tablas son auxiliares escritas por migraciones y no poseen entidad ORM | **No ejecutar**; documentar tabla y consumidor antes de cualquier propuesta, prohibido debilitar aislamiento entre tenants |
| P1 · índice seguro | **103 `RENAME INDEX`** (renombres de índice físico, no de columnas/datos); lote de cuatro índices UNIQUE presentes y comprobados en la DB temporal | Dos migraciones versionadas que conservan columnas, unicidad y protección de FKs; `up/down` simétricos |
| P2 · tipos | **43 `CHANGE COLUMN`** o paquetes con cambios de columnas: `DATETIME` con anotación Doctrine, precisiones y columnas `VARCHAR`/`CHAR`; parte es ruido de metadatos | Diferenciar widening de narrowing con tabla de datos y riesgo de locks antes de autorizar un lote; no asumir compatibilidad |
| P2 · relaciones e índices | Propuestas de agregar FKs e índices tras retirar otros; algunas relaciones existentes son **FKs compuestas (tenant_id, id)** que previenen cruces de tenant | Nunca aplicar un dump que quite FKs compuestas a favor de FKs globales; revisar plan de consultas y cardinalidad por tabla |
| P3 · alineación restante | Otros nombres de índices y diferencias de mapeo/manuales | Agrupar por consumidor/esquema en issues independientes con aceptación y MariaDB de prueba |

**Resultado verificado del primer lote:** sobre la misma MariaDB temporal, el dump pasó de **349 a 345** sentencias; ninguno de los cuatro renombres persistió como diferencia. Aplicar las dos versiones dio success (4 SQL); revertirlas en orden inverso devolvió los **4 índices únicos originales**, y reaplicarlas recuperó los **4 índices nuevos** con sus mismas columnas. El restore de un dump de la base temporal recreó **97/97 tablas**. Además, los cuatro tests de integración, PHPStan y Rector dry-run terminaron en verde. Este resultado no equivale a esquema completamente sincronizado, producción validada ni prueba de restauración de datos reales.

**Importante:** las clases de evidencia son categorías operativas, no una afirmación de que las 349 sentencias sean seguras ni de que el drift desaparezca tras este primer lote. Un resultado `doctrine:schema:validate` todavía puede indicar drift. No ejecutar `doctrine:schema:update --force` ni habilitar `doctrine:schema:update` automático.

## Grupos 01 y 02: renombres únicos sin pérdida de datos

El contraste `SHOW INDEX` de las tablas sintéticas confirma que los cuatro índices fuente existen, todos `Non_unique=0`, y el único cambio son sus **nombres**, preservando la misma lista de columnas, cardinalidad y reglas:

| Grupo | Tabla | Índice original → nombre Doctrine | Columnas |
| --- | --- | --- | --- |
| 01 | `condor_tenant` | `UNIQ_TENANT_SLUG` → `UNIQ_7B8C3E58989D9B62` | `slug` |
| 01 | `condor_tenant_domain` | `UNIQ_DOMAIN_HOSTNAME` → `UNIQ_B8F8428BE551C011` | `hostname` |
| 02 | `condor_tenant_domain` | `uniq_domain_primary_verified_tenant` → `UNIQ_B8F8428B9676AA7E` | `primary_verified_tenant_id` (generada; nullable) |
| 02 | `condor_storefront_profile` | `uniq_storefront_profile_tenant` → `UNIQ_5DBD65159033212A` | `tenant_id` |

Se usan `ALTER TABLE … RENAME INDEX` de MariaDB: no se borra ni reconstruye una tabla, no se cambia ninguna columna, no se pierde unicidad y se mantiene el esquema multitenant. Los cuatro renombres tienen operaciones `down` inversas; cada migración exige sus índices existentes y falla cerrado si el esquema esperado no coincide. MariaDB puede confirmar DDL parcialmente al fallar entre sentencias: **un rollback de código no sustituye un restore**; inspeccionar el estado y aplicar repair versionado si el segundo renombre falla.

## Validación y política de operación

1. **Offline**: usar una instancia MariaDB aislada (socket local, sin conexión a bases de clientes), aplicar todas las migraciones versionadas y capturar `SHOW INDEX` antes del lote. Sembrar únicamente registros ficticios cuando se pruebe unicidad.
2. **Backup**: exportar con `mariadb-dump --single-transaction` a archivo temporal, y verificar **restore** en una segunda base local; no aceptar un archivo no probado como respaldo.
3. **Dry-run de alcance**: comparar los cuatro `RENAME INDEX` con el inventario, ejecutar `doctrine:migrations:execute <versión> --up --no-interaction` en orden en la base temporal, validar que columnas, unicidad y FKs son idénticos salvo nombres.
4. **Rollback**: ejecutar `--down` en orden inverso, comprobar índices originales y datos intactos, reaplicar `--up` para probar repetibilidad de la migración versionada. No usar `DROP` ni recrear tablas.
5. **CI**: `python3 -m unittest discover -s tests/integration -p 'test_doctrine_historical_drift.py'`, `php -l` para ambas clases, `Backend PHP / MariaDB` que aplica migraciones, `CI Factory v1 / Validar` exact-HEAD y Rector/PHPStan.
6. **Producción**: D-054 exige allowlist/dry-run, **backup exitoso previo**, opt-out `CONDOR_AUTO_MIGRATE=0`, comprobación posterior del schema y smoke antes de tocar caché; toda operación irreversible requiere aprobación separada del dueño. Este PR no ejecuta ni autoriza el deploy.

**Reversión:** `down` restaura los nombres originales sin `DROP`; para un problema de datos se requiere restore autorizado en una ventana de mantenimiento (no ejecutar automáticamente). Siguiente grupo recomendado: registrar exactamente las FKs compuestas y comparar tipos de columnas con una migración aditiva demostrable, sin debilitar integridad.
