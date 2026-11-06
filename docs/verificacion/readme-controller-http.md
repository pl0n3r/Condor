# Condor #1555 / #1628 · pruebas HTTP README, destino Doctrine y permisos

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

`CI Condor / Pruebas de contrato e integración` ejecuta `python3 -m unittest discover -s tests/integration -p 'test_*.py'`. El paso está antes de Composer ordinario. El test prepara el autoload Composer **solo cuando falta**, genera un `APP_SECRET` efímero en el proceso, migra un esquema **descartable** y ejecuta PHPUnit con kernel real. **Antes de comprobar PHP, instalar Composer, ejecutar migraciones o construir fixtures**, `_require_disposable_doctrine_destination` valida `APP_ENV` y la URL efectiva de Doctrine DBAL, incluidas todas sus opciones de consulta. No hace llamadas HTTP productivas.

La preparación exige **`APP_ENV=test`, esquema `mysql`/`mariadb`, host de loopback** (`127.0.0.1`, `localhost` o `::1`), puerto 3306, y **una sola ruta de base `condor_[a-z0-9_]+`** que contenga `test` o `_ci_`. No basta inferir aislamiento del nombre: es una defensa parcial, complementada por la procedencia explícita del servicio MariaDB 11.4 descartable de GitHub Actions; no es prueba de que cualquier servicio local sea aislado.

**El parámetro `DATABASE_URL` de Doctrine DBAL puede sobreescribir el host/base de la ruta del URL mediante query.** Se analizan todas las parejas de consulta con `parse_qsl(..., strict_parsing=True)`, se rechazan duplicados, claves codificadas, opciones desconocidas y cualquier `host`, `dbname`, `dbName`, `unix_socket`, `socket`, `path`, `port` o `url` (incluyendo variantes de mayúsculas). La lista positiva permite **solo** `serverVersion=mariadb-X.Y[.Z]` y `charset=utf8mb4`; ambos son parámetros no relacionados con el destino, necesarios para la URL real del CI. Un query malformado, fragmento, host remoto, ruta adicional, puerto no autorizado, base no admitida o entorno distinto fallan **antes de Composer/migraciones/fixtures**. La prueba negativa es ejecutable sin servidor de DB; no imprime credenciales de conexión.

El fixture usa solo `@example.test` y sufijos aleatorios; no escribe datos identificables, claves o tokens persistentes en GitHub.

## Criterios de aceptación

**AC-01 (#1628):** la guardia Python prueba URLs válidas (incluida URL real de CI) y una matriz negativa de overrides `DATABASE_URL`, query ambiguo/duplicado, host remoto, base no aislada, `APP_ENV != test`, puerto y ruta no autorizados **antes de cualquier efecto externo**. No se llama a Doctrine para descubrir el destino: se permite exclusivamente la configuración que DBAL no puede redirigir mediante query.

**AC-02 (#1628):** cuatro GET 200 y cuatro POST 201 reales; B lee solo B; A no puede acceder a B (404). Antes de crear el primer proveedor, GET de proveedores retorna 200 con `suppliers=[]` (proveedor ausente). Cuatro POST de lector devuelven 403, **cuatro GET de usuario A con permisos de escritura pero sin `*.view` devuelven 403**, cuatro GET anónimos 401. El test usa `WebTestCase` real y falla ante diferencias, sin dobles de controladores.

**AC-03 / AC-04 (#1628):** los checks `CI Factory v1 / Validar`, `Validar`, SonarCloud y CodeQL se acreditan solo cuando sean terminales y `success` sobre el HEAD exacto, con revisión CodeRabbit FINAL una sola vez después de CI verde. El texto por sí solo no acredita CI, permiso jurídico ni aislamiento productivo.

**Riesgo y reversión:** solo test Python y esta hoja de evidencia, dentro de claims V3 propios de #1628, con el antecedente #1555/#1619 ya fusionado. Sin runtime, matriz consolidada, operaciones irreversibles, deploy ni gasto. Revert del squash para deshacer el añadido.
