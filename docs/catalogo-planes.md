# Catálogo de planes editable — contrato de administración (Condor #1448)

**Fase:** construcción. **Propietario autorizado:** exclusivamente `ROLE_PLATFORM_OWNER` en `/adminpl0n3r`.  
**Fuente de verdad:** `Plan`, `PlanVersion`, `Capability` y `CommercialCatalogReader`; no duplicar los entitlements ni crear un segundo catálogo.

## Operación

La pantalla `GET /adminpl0n3r/planes` muestra los planes vigentes con nombre, límites no negativos y capacidades incluidas. Debe distinguir **sin tarifa definida** (`monthly_amount=null`, `annual_amount=null`) de **precio cero**, y no proponer ni inferir precios. El editor envía `POST /adminpl0n3r/api/plans/{key}` con `name`, `limits`, `capabilities` y `expected_version`. Las tarifas, contratos, proveedor de pago y facturación no son parámetros editables en esta operación.

- Requiere usuario activo con rol de propietario de plataforma, sesión y CSRF válido; denegar a tenant/anon y entradas extra.
- Validar nombre no vacío, límites como mapa de claves permitidas y enteros/banderas no negativos, capacidades conocidas y sin duplicados.
- `expected_version` evita pisar cambios concurrentes; ante conflicto devolver 422 sin escritura.
- La edición es una transacción: actualizar nombre, cerrar versión vigente, crear `PlanVersion` sucesora con importes **nulos** y `quote_required=true`, conservar verticales y add-ons existentes, asociar capacidades seleccionadas y registrar un `PlatformAuditEvent`.
- La auditoría incluye identidad del actor, fecha UTC, plan, estado anterior y posterior (nombre, versión, límites y capacidades), sin datos personales ni tokens en el contexto.
- `CommercialCatalogReader` sigue siendo la entrada de la vista técnica existente (#639); la versión vigente alimenta límites y entitlements sin modificar suscripciones previas.
- **Historia comercial preservada:** la semilla de `2026-09-27` permanece consultable con sus valores históricos (los contratos existentes dependen de esa fecha); desde `2026-10-30` se materializa una **versión 2 sin tarifas** (`monthly_amount=null`, `annual_amount=null`, `quote_required=true`). La ejecución idempotente conserva capacidades, límites, verticales, add-ons e historial; no aprueba ni inventa tarifas actuales.
- Pruebas únicamente con planes/empresas sintéticos. Cualquier despliegue o pago real requiere las puertas independientes.

## Estados y reversión

La vista muestra vacío cuando no hay planes, error cuando no se puede guardar, éxito con versión actualizada y un mensaje accionable ante edición concurrente. El cambio de datos del catálogo es versionado y auditado: no borrar historia. Revertir el código no revierte registros ya creados; una reversión operativa restaura desde una nueva versión auditada, nunca destruye historial.

## Criterios de aceptación

1. Un propietario puede leer y editar nombre, límites y capacidades desde el panel; el servidor verifica autorización y CSRF.
2. La edición conserva entidades relacionadas, registra anterior/nuevo y fecha/actor en auditoría y alimenta el lector existente.
3. Planes iniciales sin tarifa, sin importes de ejemplo; no se habilita cobro ni contrato.
4. Las pruebas `tests/test_catalogo_planes_admin.py::CatalogoPlanesAdminTests::test_criterio_principal` y el CI `Validar` pasan sobre el mismo HEAD.

**Fuera de alcance:** go-live, precios y tarifas reales, cobros, facturas, proveedores, datos de clientes reales y decisiones financieras del dueño.
