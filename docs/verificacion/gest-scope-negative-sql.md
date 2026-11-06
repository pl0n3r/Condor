# Condor #1637 · negativos de ámbito tenant, entidad y sede (MariaDB)

**Estado:** preparación y ejecución de pruebas sintéticas pre-live. La evidencia terminal de CI del HEAD definitivo debe comprobarse en GitHub; este informe **no** constituye certificación legal ni prueba de producción.

## Autoridad y alcance

- Issue: [#1637](https://github.com/pl0n3r/Condor/issues/1637), hija de #1621, contraste de las brechas GEST de #1543.
- Infraestructura real de prueba: Symfony 7.4 WebTestCase, Doctrine ORM/DBAL y **MariaDB 11.4** descartable en el job `Backend PHP / MariaDB`. Se usan únicamente datos fabricados en el test y referencias `example.test`.
- Test ejecutable en CI: `tests/php/Http/GestScopeNegativeSqlTest.php`, autodetectado por `vendor/bin/simple-phpunit` en el job de backend.
- Test auxiliar explícito: `tests/e2e/test_gest_scope_negative_sql.py`, que invoca el fixture PHPUnit y rechaza resultados ausentes, fallos o runtime SQL no disponible.
- Claims del test: únicamente archivos nuevos autorizados por la reserva V3 tras la renovación contractual. Ni fuente productiva ni matriz de cobertura se modifican.

## Aserciones exigidas y testigos

| Frontera | Camino ejecutable real | Negativo esperado |
| --- | --- | --- |
| Tenant A/B (2 tenants) | `BranchApiSupport::branchScope`, Doctrine | Una sede del tenant B no puede leerse desde A (404). Las consultas parametrizadas por tenant/sede no devuelven filas de B. |
| Entidades legales | `ApiContextController` + `LegalEntity` + `Branch` | Entidad y sede ocultas no aparecen en JSON de un delegado con permisos solo para su sede. |
| Sedes y roles | `BranchAuthorization` + `BranchRoleAssignment` + `Role` | Una sede no asignada falla cerrada (403); un delegado de solo lectura no puede crear roles ni elevar privilegios (403). |
| IDOR y CSRF | `BranchAccessController` con sesión Symfony | ID foráneo devuelve 404; escritura sin token CSRF devuelve 403; input inesperado devuelve 422. |
| SQL/rollback | Doctrine DBAL contra MariaDB descartable | Los intentos denegados no agregan roles o registros de auditoría; el fixture revierte su transacción y comprueba ausencia de filas. |
| Exportación | `TenantCsvReportService::build` real | La proyección CSV contiene solo filas del tenant A y columnas allowlist; descarta filas de B y campos sensibles, sin extrapolar a todo GEST. |
| Errores/vacíos | Respuestas HTTP Symfony + repositorios SQL | `403/404/422` son respuestas terminales; no se convierten en éxito ni se imprime contenido sensible. |

**Regresión negativa:** las expectativas HTTP y SQL se comprueban sobre servicios efectivos, no sobre un mock que repite la respuesta esperada. Eliminar los guards tenant/rol, la verificación CSRF o el filtro SQL debe romper una o más aserciones. Esta propiedad no sustituye una campaña formal de mutación instrumentada.

## Límites explícitos

Las 41 filas del análisis integral GEST permanecen `NO_VERIFICABLE`: validar aislación de contexto, roles y CSV parcial no prueba el E2E completo, legal hold, aprobaciones humanas, normativas, cambios concurrentes integrales, minimización de alertas sensibles o flujos transaccionales multiárea. **F3/F4** y RR. HH. siguen denegados bajo #742/B, **D-059** y **D-063**, sin habilitar endpoints ni permisos laborales, sin PII médica ni datos reales.

No se ejecutan backups productivos, proveedores, go-live, despliegues, migraciones destructivas ni cambios en `docs/matriz-cobertura.md`.

## Reproducción y evidencia

```sh
# CI de backend ya levanta MariaDB descartable, instala Composer y migra esquema.
vendor/bin/simple-phpunit --configuration phpunit.xml.dist --filter testNegativeTenantLegalEntityBranchRoleAndSqlRollback tests/php/Http/GestScopeNegativeSqlTest.php
python3 -m unittest discover -s tests/e2e -p test_gest_scope_negative_sql.py -v
```

`CI Factory v1 / Validar`, `Backend PHP / MariaDB`, CodeQL, SonarCloud y revisión FINAL CodeRabbit requieren evidencia autenticada sobre el HEAD exacto antes de squash. Un resultado CI pendiente, una prueba no ejecutada o CodeRabbit omitido se reportan sin presumir PASS.

**Reversión:** revertir el squash de los tests y este informe. El test abre y revierte una transacción sobre MariaDB de CI, sin alterar servicios productivos.
