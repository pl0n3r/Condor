# Titularidad jurídica y operaciones interempresa en Condor

**Decisión canónica:** D-052 en [`ESPECIFICACIONES.md`](../ESPECIFICACIONES.md). **Trabajo relacionado:** [Issue #177](https://github.com/pl0n3r/Condor/issues/177).  
**Estado:** contrato de arquitectura; este documento no declara funciones ni migraciones desplegadas.  
**Fuente de implementación:** `main` y los tests del SHA fusionado.  
**Dependencias:** V 0.1.21 y requisitos del primer cliente ya integrados en `main`; [Inventario #171 / PR #172](https://github.com/pl0n3r/Condor/pull/172) aplica la primera frontera ejecutable. Este documento sigue siendo contrato arquitectónico y no acredita por sí solo deploy ni migración productiva.

## 1. Modelo de pertenencia

`Tenant` es la cuenta/grupo operativo contratado en Condor, no una razón social ni un almacén. `LegalEntity` es el titular jurídico/fiscal que opera dentro del tenant. Un tenant puede tener varias entidades legales; onboarding conserva una entidad primaria y una sede inicial. `Branch` es una sede operativa y `InventorySource` es una ubicación lógica o física de stock. Ni sede ni fuente sustituyen al titular jurídico.

Los dominios con propiedad jurídica deben registrar un `legal_entity_id` **efectivo**, tenant-owned, en su operación y en cualquier proyección que se consulte o exporte: inventario, pedidos/ventas, abastecimiento, costos y fabricación. El catálogo `Product`/`ProductVariant` puede continuar compartido a nivel tenant; compartir la ficha **no comparte automáticamente** saldo, titularidad, costo, precio efectivo, permiso ni historial.

### Lo que NO significa

- Dos razones sociales en un grupo no obligan a crear dos tenants, ni habilitan automáticamente acceso transversal.
- Una cuenta multi-entidad no equivale a una sola contabilidad o existencia consolidada.
- Seleccionar una razón social en UI no es una autorización.
- Una transferencia física de stock no registra por sí sola una compra, venta, factura, prestación de servicio ni cambio de titularidad.
- Este contrato no autoriza migración, backfill, deploy ni pruebas destructivas de producción.

## 2. Alcance y autorización

| Capa | Regla comprobable |
| --- | --- |
| Identidad | La sesión autentica usuario y resuelve tenant con membresía activa. |
| Contexto | La razón social efectiva se selecciona explícitamente entre las autorizadas; no se obtiene del slug, nombre, URL no verificada o fuente elegida por el cliente. |
| Sede | Un delegado conserva el alcance de sus roles por sede; la asociación a una entidad legal no le concede acceso a todas sus sedes. |
| Fuente lógica | Al no tener sede, necesita permiso explícito de gestión/consulta en la entidad legal; no hereda permisos de una sede ajena. |
| Lectura | API, dashboards, historial y exportaciones filtran por tenant, entidad legal y sedes/fuentes permitidas. No unir resultados de otra entidad solo porque pertenezca al tenant. |
| Escritura | Resolver y autorizar tenant, entidad legal, sede/fuente y permiso server-side **antes** de cambiar saldos; petición cross-tenant o cross-entity falla sin efectos. |
| Propietario | Si el producto permite gestión grupal, el owner puede operar entidades del mismo tenant mediante contexto explícito; no por mezclar ids de entidades en una sola transacción. |

La capa HTTP determina 403/404/409 según identidad, existencia y compatibilidad legacy, evitando revelar registros ajenos. La base de datos es segunda barrera: FKs y unicidades compuestas incorporan tenant y entidad legal donde corresponde.

## 3. Inventory: invariantes del Slice 4

1. Cada `InventorySource` identifica exactamente un `Tenant` y una `LegalEntity` del mismo tenant.
2. Una fuente de tipo sede referencia una `Branch` cuya `legalEntity` coincide. Fuente lógica declara su entidad aunque `branch_id` sea NULL.
3. `InventoryBalance` representa cantidad por `tenant + legal_entity + source + variant`. La variante puede ser tenant-owned sin pertenecer exclusivamente a una sociedad.
4. `InventoryMovement` es auditable y preserva titularidad, origen/destino cuando proceda, actor y estado resultante. No puede referenciar fuente, saldo o transferencia de otra entidad legal.
5. `InventoryTransfer` normal requiere fuente origen y destino **de la misma entidad legal**; `tenant + entidad + source + variant` constituyen la frontera de consistencia.
6. La idempotencia y el locking de escritura deben respetar esa frontera. Repetir una misma operación no crea movimientos ni auditorías comerciales duplicadas.
7. Ajustes y transferencias que fallen conservan saldos, movimientos y auditoría de la operación como una unidad coherente. `allow_backorder` no permite eludir titularidad.
8. Si `Branch::legalEntity()` es NULL en una sede anterior al cambio, la API de inventario rechaza explícitamente esa sede sin asignarla en silencio a la entidad primaria.

### Persistencia

Las referencias fuertes deben impedir asociaciones cruzadas aun si se omite un filtro en un controlador. Para inventario, usar las claves compuestas equivalentes a `(tenant_id, legal_entity_id, id)` en fuente y transferencia y FKs desde saldo/movimiento/transferencia a su entidad y fuente; preservar las FKs tenant + variant existentes. Un campo `legal_entity_id` aislado **sin FKs compuestas** no demuestra este contrato.

### Frontera sede ↔ fuente en la propia base de datos

Hay una diferencia crucial entre dos FKs correctas por separado y **una pertenencia jurídica coherente**. Una fuente con `(tenant=A, legal_entity=X, branch_id=B)` puede pasar tanto la FK `(tenant,legal_entity)` a `LegalEntity` como la FK `(tenant,branch_id)` a `Branch`, aunque la sede B pertenezca a la entidad Y del mismo tenant. El constructor y la autorización HTTP lo deben rechazar, pero no son sustitutos de la segunda barrera relacional.

En el slice de Inventario, la migración deberá ofrecer una clave candidata `Branch(tenant_id,legal_entity_id,id)` y una FK desde `InventorySource(tenant_id,legal_entity_id,branch_id)` hacia esa clave. Esta FK no impide sedes legacy con `legal_entity_id=NULL` en la tabla `Branch`; sí impide crear una fuente de sede que apunte a una sede sin titular compatible. Una fuente lógica utiliza `branch_id=NULL`, declara titular de forma explícita y no hereda acceso por relación de sede. Mantener además la validación de tipo de fuente (sede requiere branch; lógica no admite branch) en dominio y, donde se soporte sin romper compatibilidad, con una restricción de base de datos.

**Regresión MariaDB requerida:** crear dos entidades jurídicas X/Y del *mismo tenant* y su sede B en Y; intentar insertar por SQL una fuente de X apuntando a B. Debe fallar por FK sin depender de `InventorySource::__construct` ni del controlador. Repetir con una sede legacy `legal_entity_id=NULL` y fuente no nula, también denegada. Una fuente lógica válida de X con sede NULL y un movimiento dentro de X siguen permitidos. Verificar `doctrine:schema:validate` y la migración en MariaDB descartable antes de incorporar el cambio; nunca corregir sedes productivas a ciegas.

La ruta de integración exige pruebas MariaDB reales que intenten insertar o modificar asociaciones cruzadas, además de pruebas del servicio. La inspección de ORM por sí sola no es evidencia suficiente.

## 4. Despliegue compatible para sedes anteriores

`Branch.legal_entity_id` existe actualmente como nullable. No aplicar `NOT NULL`, migraciones destructivas o un backfill implícito sobre producción al preparar Inventario.

1. **Expand:** añadir campos/índices y servicios compatibles con sedes antiguas; rechazar claramente operaciones nuevas que carezcan de titular, sin falsificar pertenencia.
2. **Diagnóstico read-only:** contar sedes sin entidad por tenant y verificar la entidad primaria existente. Una razón social primaria no siempre determina el dueño correcto de una sede histórica.
3. **Asignación autorizada:** proponer mapeo de sedes a entidades jurídicas, preservar evidencia y ensayarlo en datos descartables. Si hay ambigüedad, no asignar automáticamente.
4. **Validación:** casos de una y dos entidades dentro del mismo tenant, un tenant ajeno, rollback, índices/FKs y rutas de administración.
5. **Contract posterior:** estrechar nullabilidad solo cuando la reconciliación y compatibilidad de todas las rutas estén demostradas, con despliegue y migración productivos aprobados separadamente.

El pipeline debe distinguir `IMPLEMENTADO`, `VALIDADO EN CÓDIGO`, `DESPLEGADO` y `VALIDADO EN PRODUCCIÓN`; ningún test con MariaDB descartable acredita un backfill real.

## 5. Intercompany no es transferencia interna

Cuando hay cambio de titularidad jurídica se usa **otro caso de uso**. El contrato futuro debe registrar entidad origen, entidad destino, motivo/tipo (venta entre sociedades, fabricación por encargo, compra o devolución), mercancía/cantidades, valoración y moneda, documento/referencia, impuestos cuando apliquen, eventos/actor y reversión o compensación. El movimiento físico del inventario será un efecto transaccional del contrato intercompany, no su definición.

No se implementa en #171/#172 una excepción `crossEntity=true` ni se reinterpretan dos `InventorySource` de entidades diferentes como si compartieran saldo.

## 6. Matriz mínima de aceptación

| Caso | Resultado esperado | Capa |
| --- | --- | --- |
| Dos `LegalEntity` del mismo tenant con dos sedes | saldos e historial separados aunque usen la misma variante | MariaDB + servicio + HTTP |
| Entidad de otro tenant | rechazado al crear fuente, saldo, movimiento o transferencia | dominio + FK + HTTP |
| Fuente sede con entidad distinta a la sede | rechazada antes de persistir | dominio + FK |
| Fuente lógica de otra entidad dentro del tenant | 404/403 sin saldo ni movimiento nuevo | HTTP + MariaDB |
| Delegado autorizado solo en sede A | no consulta ni muta sede B por compartir entidad legal | permisos + HTTP |
| Transferencia dentro de una entidad | dos movimientos vinculados o ninguno | servicio + MariaDB |
| Transferencia entre entidades jurídicas | rechazada como transferencia ordinaria; intercompany separado | servicio + FK + HTTP |
| Sede legacy sin entidad | 409 diagnosticable, nunca asignación silenciosa | HTTP |
| Dos ajustes simultáneos al primer saldo | una sola fila de balance y sin lost update | concurrencia MariaDB |
| Tenant de una razón social | contexto primario automático sin selector visible innecesario | UI + HTTP |
| Tenant multi-entidad | selector solo con entidades autorizadas; no eleva permisos | UI + HTTP |

**Secuencia de entrega:** cerrar Inventario #171/#172 con gates exact-head y exact-main; después aplicar esta misma invariante a ventas, costos, fabricación y reporting mediante slices reusables. La decisión durable queda registrada en D-052 de `ESPECIFICACIONES.md`. Los RF del primer cliente sirven de validación de producto, no de condicionales de código.
