# Condor #1555 · pruebas HTTP README con tenant A/B y permisos

**Alcance:** HTTP ejecutado con kernel Symfony, Doctrine y MariaDB descartable; datos sintéticos. No demuestra go-live, Hostinger, clientes reales ni disponibilidad productiva. No modificar `docs/matriz-cobertura.md`.

La prueba canónica es `tests/integration/test_readme_controller_http.py`. Genera un `ReadmeControllersHttpSmokeTest` temporal, que invoca cuatro controladores **reales** y verifica status y aislamiento; no sustituye sus respuestas por dobles.

## Mapa ID → contrato ejecutable

| ID | Ruta GET/POST | Recibos obligatorios |
| --- | --- | --- |
| README-03-03 | `/api/v1/branches/{branchId}/orders` | GET propio 200, POST propio 201 con stock/lista/variante reales; cruce A→B 404, POST de lector 403 y GET anónimo 401 |
| README-03-04 | `/api/v1/branches/{branchId}/customers` | GET propio 200 y POST propio 201; cliente del tenant B no aparece en A; cruces 404, lector POST 403 y anónimo GET 401 |
| README-03-05 | GET `/api/v1/branches/{branchId}/inventory` y POST `/api/v1/branches/{branchId}/inventory/sources` | GET 200, creación de fuente lógica 201, fuente y SKU ajenos invisibles; cruces 404, lector POST 403, anónimo GET 401 |
| README-03-06 | `/api/v1/branches/{branchId}/purchasing/suppliers` | GET 200 y proveedor creado por POST 201; cruce 404, lector POST 403, anónimo GET 401 |
| README-07-02 | Todos los anteriores mediante `BranchApiSupport::authorizedBranchScope` | Owners separados A/B, rol lector A con permisos `orders.view`, `customers.view`, `inventory.view`, sin permisos de escritura. Eliminar guards hace fallar el test HTTP. |
| README-05-01 | Symfony + Doctrine + MariaDB en CI | Real `WebTestCase`, migraciones en base descartable, sin mocks de controller; no acredita base productiva. |

**Estado:** instrumentado como prueba ejecutable; la aceptación definitiva depende de que `CI Factory v1 / Validar` y `Validar` estén en `success` terminal sobre el **HEAD exacto**. No promover cobertura de live ni inventar recibos antes de CI.

## Ejecución en CI y límites de seguridad

`CI Condor / Pruebas de contrato e integración` ejecuta `python3 -m unittest discover -s tests/integration -p 'test_*.py'`. El paso está antes de Composer ordinario. El test prepara el autoload Composer **solo cuando falta**, genera un `APP_SECRET` efímero en el proceso, migra un esquema **descartable** y ejecuta PHPUnit con kernel real. No hace llamadas HTTP productivas.

La preparación está protegida por `APP_ENV=test`, URL de MariaDB con host de loopback (`127.0.0.1`, `localhost` o `::1`) y nombre de base `condor_*` que contenga `test` o `_ci_`. Una URL de Hostinger, una base no aislada o un entorno distinto **fallan cerrado**. Cada fixture usa `@example.test` y sufijo aleatorio; no se escriben datos identificables, claves o tokens persistentes en GitHub.

## Criterios de aceptación

**AC-01:** cuatro GET 200 y cuatro POST 201 reales; B lee únicamente su propia sede; solicitudes A a B devuelven 404. **AC-02:** cuatro POST de lector A devuelven 403, cuatro GET anónimos 401; una autorización omitida no es intercambiable con 422, 200 o 201. **AC-03:** checks de CI exact-HEAD terminales verdes; lo contrario permanece `NO_VERIFICABLE`.

**Riesgo y reversión:** solo test Python y esta hoja de evidencia, dentro de claims V3 #1555. Sin runtime, matriz consolidada, operaciones irreversibles, deploy ni gasto. Revert del squash para deshacer el añadido.
