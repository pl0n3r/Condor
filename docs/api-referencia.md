# Referencia OpenAPI de administración — Condor (#1691, correcciones #1703)

La referencia versionada está en [`openapi/condor-admin-v1.json`](../openapi/condor-admin-v1.json) (OpenAPI **3.1.0**). Describe las rutas HTTP reales declaradas con atributos Symfony `#[Route]` bajo **`/api/v1/`** (administración del tenant) y **`/adminpl0n3r/api/`** (propietario de plataforma). No activa, publica ni modifica endpoints.

## Cómo regenerar y verificar

Desde la raíz del repositorio, con **Python 3.10 o posterior**, sin depender de un servidor en ejecución:

```sh
python3 tests/test_openapi_condor_referencia.py --write
python3 tests/test_openapi_condor_referencia.py --check
python3 -m unittest discover -s tests -p 'test_openapi_condor_referencia.py'
```

- `--write` vuelve a leer `src/Http/Controller/**/*.php` (archivos PHP con atributos Route, también en subdirectorios o sin sufijo Controller.php) y escribe JSON estable, con claves ordenadas y sin marcas temporales. La segunda regeneración sobre el mismo código no cambia el archivo.
- `--check` compara bytes del artefacto generado, sin modificarlo.
- El test verifica cobertura de método/ruta, permisos de acceso, parámetros de ruta, errores y determinismo; **falla si se agrega, elimina o modifica un método/ruta** sin actualizar el JSON; incluye una fixture PHP con una ruta nueva y varios métodos HTTP.
- La prueba también falla cerrada ante rutas sin métodos explícitos, duplicados o permisos no inferibles; se debe revisar la implementación real antes de ampliar reglas.

## Lectura del contrato

Los pagos nuevos responden HTTP 201 y su repetición con la misma llave idempotente devuelve HTTP 200. Seguridad social exige además membresía activa de `tenant_owner` en sus operaciones; la vista sintética no puede suplir esa validación del backend.

Cada operación tiene `operationId` (nombre Symfony y sufijo HTTP si el mismo nombre cubriría varios métodos), `x-controller` (archivo/método PHP), `x-required-permissions`, `x-csrf-required`, parámetros de ruta/consulta y, cuando corresponde, `requestBody`.

- Las APIs de tenant usan los permisos efectivos del backend (`catalog.view`, `inventory.create`, etc.). `tenant_owner` significa autorización por rol del tenant; el contexto del tenant exige membresía y sede accesible. En reportes CSV, `from` y `through` son obligatorios. La extensión `x-required-permissions-by-kind` exige **un solo permiso según `kind`**: `inventory` y `purchases` requieren `inventory.view`; `sales` requiere `orders.view`. No se exigen ambos permisos simultáneamente.
- La superficie del propietario exige el rol `ROLE_PLATFORM_OWNER`. La autorización efectiva sigue siendo la implementada por Symfony.
- Las mutaciones con protección explícita incluyen el encabezado `X-CSRF-Token`. Las mutaciones de documentos `attachEvidence` y `requestReview` delegan el control al helper `requireDocumentWriteCsrf`. También se documentan los controles delegados de contratación y de vistas sintéticas.
- Las respuestas 4xx referencian el sobre base de [contratos REST](api-rest-contracts.md): `bad_request`, `unauthenticated`, `forbidden`, `not_found`, `conflict`, `validation_error`, `rate_limited`. La respuesta 500 usa `InternalError`. El sobre `ApiError` solo exige `error` porque existen respuestas HTTP 422 con ese único campo, especialmente en planes; los metadatos `message`, `status` y `request_id` no están garantizados para todas las rutas. Esta es la **familia de errores del contrato**, no una promesa de que todos los códigos se emitan en todas las rutas.
- Únicamente se infieren campos desde listas literales de `payload($request, [...])`. Si se pasa un allowlist variable, como `payload($request, $allowed)`, el esquema queda abierto: nunca se capturan claves de la respuesta como entrada. En los endpoints con payload no tipado en el atributo Route, los esquemas JSON son orientativos; la validación precisa vive en los controladores y las pruebas HTTP. No inventar campos obligatorios, formatos o ejemplos con datos reales.

## Límites y seguridad

La generación es **estática**, basada en los atributos de los controladores. Las rutas importadas desde configuración adicional o cambios a la resolución de rutas deben revisarse por separado; `config/routes.yaml` importa los atributos de `src/Http/Controller/`. El nombre de la cookie de sesión puede variar por despliegue. No se incluyen secretos, valores de sesión, identificadores reales, ejemplos de personas, dumps ni URLs productivas.

Este JSON es documentación interna para desarrollo y QA. No es una publicación pública ni evidencia de validación en producción. La eliminación se revierte con el squash del PR.
