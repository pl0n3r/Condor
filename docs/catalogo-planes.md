# Catálogo de planes editable — contrato de administración (Condor #1448)

**Fase:** construcción. **Propietario autorizado:** exclusivamente `ROLE_PLATFORM_OWNER` en `/adminpl0n3r`.  
**Fuente de verdad:** `Plan`, `PlanVersion`, `Capability` y `CommercialCatalogReader`; no duplicar los entitlements ni crear un segundo catálogo.

## Operación

La pantalla `GET /adminpl0n3r/planes` muestra los planes vigentes con nombre, límites no negativos y capacidades incluidas. Debe distinguir **sin tarifa definida** (`monthly_amount=null`, `annual_amount=null`) de **precio cero**, y no proponer ni inferir precios. El editor envía `POST /adminpl0n3r/api/plans/{key}` con `name`, `limits`, `capabilities` y `expected_version`. Las tarifas, contratos, proveedor de pago y facturación no son parámetros editables en esta operación.

- Requiere usuario activo con rol de propietario de plataforma, sesión y CSRF válido; denegar a tenant/anon y entradas extra.
- Validar nombre no vacío, límites como mapa de claves permitidas y enteros/banderas no negativos, capacidades conocidas y sin duplicados.
- `expected_version` evita pisar cambios concurrentes; ante conflicto devolver 422 sin escritura.
- La edición es una transacción: actualizar nombre, cerrar versión vigente y crear `PlanVersion` sucesora **conservando exactamente la tarifa, moneda y precio de complementos/verticales vigentes**, junto con límites, relaciones y auditoría `PlatformAuditEvent`. Los importes solo se modifican en la API específica `/prices`, nunca por editar el nombre.
- La auditoría incluye identidad del actor, fecha UTC, plan, estado anterior y posterior (nombre, versión, límites y capacidades), sin datos personales ni tokens en el contexto.
- `CommercialCatalogReader` sigue siendo la entrada de la vista técnica existente (#639); la versión vigente alimenta límites y entitlements sin modificar suscripciones previas.
- **Historia comercial preservada:** la semilla de `2026-09-27` permanece consultable con sus valores históricos (los contratos existentes dependen de esa fecha); desde `2026-10-30` se materializa una **versión 2 sin tarifas** (`monthly_amount=null`, `annual_amount=null`, `quote_required=true`). La ejecución idempotente conserva capacidades, límites, verticales, add-ons e historial; no aprueba ni inventa tarifas actuales.
- Pruebas únicamente con planes/empresas sintéticos. Cualquier despliegue o pago real requiere las puertas independientes.

## Estados y reversión

La vista muestra vacío cuando no hay planes, error cuando no se puede guardar, éxito con versión actualizada y un mensaje accionable ante edición concurrente. El cambio de datos del catálogo es versionado y auditado: no borrar historia. Revertir el código no revierte registros ya creados; una reversión operativa restaura desde una nueva versión auditada, nunca destruye historial.

## Criterios de aceptación

1. Un propietario puede leer y editar nombre, límites y capacidades desde el panel; el servidor verifica autorización y CSRF.
2. La edición conserva entidades relacionadas, registra anterior/nuevo y fecha/actor en auditoría y alimenta el lector existente.
3. Historia de v1/v2 preservada, v2 sin tarifa y v3 con importes aprobados en #1684; ninguna edición de límites borra importes ni habilita cobro o contrato.
4. Las pruebas `tests/test_catalogo_planes_admin.py::CatalogoPlanesAdminTests::test_criterio_principal` y el CI `Validar` pasan sobre el mismo HEAD.

**Fuera de alcance:** go-live, precios y tarifas reales, cobros, facturas, proveedores, datos de clientes reales y decisiones financieras del dueño.


## Tarifas owner-only por versión (#1682)

El propietario puede usar el formulario en `GET /adminpl0n3r/planes` para fijar moneda ISO-4217, precio mensual y anual (enteros no negativos en unidades menores, COP por defecto), `quote_required` y `effective_from` (hoy = vacío, o fecha UTC futura). `null` significa **sin tarifa definida** y `0` es **precio cero**, nunca intercambiables. El estado *Cotizar* requiere ambos importes `null`. El formulario crea una nueva versión: no altera la tarifa de contratos o versiones anteriores ni habilita cobros.

API existente del mismo catálogo, con **sesión owner-only, X-CSRF-Token, rechazo de campos extra, expected_version exacto y transacción con bloqueo pesimista**:

- `POST /adminpl0n3r/api/plans/{key}/prices`: JSON con **exactamente** `expected_version` (int), `currency` (tres letras mayúsculas), `monthly_amount` (int|null), `annual_amount` (int|null), `quote_required` (bool), `effective_from` (null, `YYYY-MM-DD` futuro o `YYYY-MM-DDTHH:MM:SSZ` UTC futuro), `addons` y `verticals` (objetos; pueden ser vacíos). Cada clave de complemento o vertical pertenece a las relaciones del plan y contiene exactamente esos cuatro campos de tarifa excepto `expected_version`/`effective_from`. Las monedas de las tarifas relacionadas deben coincidir con la del plan.
- `POST /adminpl0n3r/api/plans/{key}/prices/cancel`: JSON solo `{"expected_version": N}` para la última versión programada, únicamente **antes** de su fecha de vigencia. La versión predecesora recupera su vigencia y se audita la cancelación. Rechaza versiones ya vigentes o conocidas por otro editor.
- Fecha efectiva de ahora o futura; solo se admite **una versión futura pendiente por plan**. El lector `CommercialCatalogReader::current($at)` selecciona la vigente para ese instante, incluidos los precios de complementos y verticales asociados a esa versión. Un complemento no cambia globalmente ni reescribe su pasado.
- `PlatformAuditEvent` registra actor, UTC, clave y estado `old/new` de cada programación y cancelación, sin tokens ni datos personales. Las cotizaciones calculan importes en servidor; no hay pasarela, factura ni cobro.

Migración `Version20261106150000`: columna JSON opcional `price_options` en `PlanVersion`, aditiva y compatible con las versiones anteriores (sin snapshot explícito se mantiene el precio heredado del add-on). El rollback **nunca** elimina historial de tarifas: requiere reconciliación manual mediante versión sucesora auditada. En la futura pantalla React, #1683 consumirá estas APIs sin introducir un catálogo paralelo.
