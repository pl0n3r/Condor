# Contratos REST administrativos de Condor

Estado: **baseline V 0.1.12**. Este documento define el contrato público interno entre Symfony y las superficies administrativas React. Los endpoints pueden ampliar sus payloads de forma compatible; no pueden cambiar silenciosamente semántica, tipos o códigos de error ya publicados.

## 1. Versionado y transporte

- Las APIs administrativas de tenant viven bajo `/api/v1/`.
- Las APIs exclusivas del propietario conservan `/adminpl0n3r/api/` mientras exista esa superficie.
- JSON usa UTF-8 y nombres `snake_case`.
- Los identificadores de dominio son ULID de 26 caracteres.
- Colecciones vacías se representan como `[]`, no como `null`.
- Un campo nullable debe declararse deliberadamente; la ausencia de un campo y `null` no se consideran equivalentes por defecto.
- Toda respuesta lleva `X-Request-Id`; los errores incluyen el mismo valor en `request_id`.

## 2. Errores 4xx

Todo error HTTP 4xx de una superficie API usa:

```json
{
  "error": "validation_error",
  "message": "Los datos enviados no son válidos.",
  "status": 422,
  "request_id": "01K...",
  "details": {}
}
```

`details` es opcional y solo contiene contexto seguro que el cliente necesita para recuperarse. Nunca contiene stack traces, SQL, secretos, headers de autenticación ni datos de otro tenant.

Códigos base:

| HTTP | `error` | Semántica |
| ---: | --- | --- |
| 400 | `bad_request` | JSON o estructura de solicitud inválida |
| 401 | `unauthenticated` | falta una sesión autenticada válida |
| 403 | `forbidden` | identidad válida sin autorización suficiente |
| 404 | `not_found` | recurso ausente o no visible dentro del alcance permitido |
| 409 | `conflict` | conflicto con el estado persistido actual |
| 422 | `validation_error` | payload bien formado que incumple el contrato |
| 429 | `rate_limited` | límite de solicitudes excedido |

Un endpoint puede definir un código más específico cuando la UI necesita una recuperación concreta. Ejemplo: `branch_scope_required` mantiene HTTP 403 y entrega el tenant seguro dentro de `details.tenant`.

## 3. Errores 5xx

Los 5xx pertenecen al mecanismo de incidentes seguro de Condor. Mantienen como mínimo:

```json
{
  "error": "internal_error",
  "message": "No pudimos completar esta solicitud.",
  "error_id": "01K..."
}
```

La evolución del diagnóstico privilegiado del propietario es compatible con este contrato, pero nunca convierte un 5xx en un payload de depuración público. `error_id` identifica el incidente; `request_id` identifica la solicitud cuando esté disponible en la versión del contrato.

## 4. Validación de requests

Las mutaciones sensibles:

- aceptan únicamente un objeto JSON;
- validan tipos en backend;
- rechazan campos inesperados;
- aplican CSRF cuando usan autenticación por sesión;
- resuelven tenant/sede en backend y nunca aceptan que un ID enviado por el cliente reemplace ese contexto;
- responden 422 cuando el JSON es válido pero incumple el esquema y 400 cuando el cuerpo no es JSON/objeto.

Ejemplo de creación de rol:

```json
{
  "name": "Gestor de catálogo",
  "permissions": ["catalog.view", "catalog.update"]
}
```

No se aceptan campos como `tenant_id`, `branch_id`, `owner` o equivalentes para alterar el alcance.

## 5. Contexto de administración

### `GET /api/v1/context`

Respuesta exitosa:

```json
{
  "tenant": {
    "id": "01K...",
    "name": "Empresa",
    "slug": "empresa"
  },
  "branches": [],
  "active_branch": {
    "id": "01K...",
    "name": "Principal",
    "slug": "principal",
    "is_default": true
  },
  "permissions": [],
  "version": "0.1.12"
}
```

`branches` contiene solo sedes accesibles para el actor. Solicitar una sede fuera de ese alcance falla cerrado. Si el actor pertenece al tenant pero no tiene ninguna sede accesible, el endpoint responde 403 `branch_scope_required` y puede incluir `details.tenant`.

### Roles y membresías

- `GET /api/v1/branches/{branchId}/roles`
- `POST /api/v1/branches/{branchId}/roles`
- `PATCH /api/v1/branches/{branchId}/roles/{roleId}`
- `DELETE /api/v1/branches/{branchId}/roles/{roleId}`
- `GET /api/v1/branches/{branchId}/memberships`
- `PUT /api/v1/branches/{branchId}/memberships/{membershipId}/roles/{roleId}`
- `DELETE /api/v1/branches/{branchId}/memberships/{membershipId}/roles/{roleId}`

Los IDs se resuelven siempre dentro del tenant activo. Un ID de otro tenant no debe revelar existencia ni datos del recurso.

## 6. Paginación, filtros y orden

Cuando una colección sea paginada se usa el baseline:

```json
{
  "page": 1,
  "per_page": 20,
  "total": 42,
  "has_previous": false,
  "has_next": true
}
```

- `page` inicia en 1.
- `per_page` tiene límites server-side.
- El orden por defecto debe ser determinista.
- Filtros y ordenamientos nuevos se agregan mediante parámetros nombrados y listas permitidas; nunca interpolan directamente columnas o SQL suministrado por el cliente.
- Un cambio de orden por defecto que altere comportamiento visible se trata como cambio de contrato.

## 7. Compatibilidad

Durante una transición el frontend puede aceptar temporalmente la forma anterior y la nueva, pero el backend publica una sola forma canónica nueva. La compatibilidad temporal se elimina cuando todos los consumidores versionados estén migrados.

Los contratos se prueban en integración; los flujos representativos del Admin se conservan en Playwright. Un cambio de payload sin regresión de contrato correspondiente no se considera completo.
