# Facturación y conciliación operativa de pedidos — propuesta V0 (solo construcción)

> **Estado:** PROPUESTA PARA REVISIÓN DEL DUEÑO, NO APROBADA. Documento de producto de [Condor #1513](https://github.com/pl0n3r/Condor/issues/1513), autorizado para redacción por el [comentario OWNER del 20-nov-2026](https://github.com/pl0n3r/Condor/issues/1513#issuecomment-7053851597). La aprobación de negocio de AC-01 se producirá únicamente mediante revisión expresa de su PR por el dueño; ni el presente texto ni un CI verde equivalen a aprobación.
>
> **Versión técnica de referencia:** Condor `0.1.202` (`config/version.php`); la identidad de esta entrega documental no aprueba las decisiones E1–E6, no implica publicación y no cambia cobros ni datos.
> **Restricción permanente de esta propuesta:** SIN emisión de facturas, SIN DIAN/proveedor fiscal, SIN pasarela bancaria, SIN cobros/reembolsos reales, SIN datos personales/financieros reales, SIN go-live. Todo ejemplo y prueba usa fixtures sintéticos. Los futuros cambios de esquema, API y UI se realizarán únicamente en issues y PR separados, una vez aprobadas las elecciones.

## 1. Objetivo, fuentes de verdad y frontera

Definir cómo **registrar y conciliar internamente movimientos simulados** asociados a pedidos, sin confundir el registro declarado por un operador con una transacción efectivamente verificada. No se pretende entregar una factura electrónica, asiento contable definitivo, extracto bancario o comprobante de que un tercero pagó.

Fuentes del contrato actual (leídas en `main`):
- [ESPECIFICACIONES.md, D-019](../ESPECIFICACIONES.md): permisos y auditoría de precios manuales; [D-021](../ESPECIFICACIONES.md): **pedido**, **pago** y **entrega** son dimensiones independientes, con métodos y pagos parciales configurables; [D-026](../ESPECIFICACIONES.md): V1 puede operar sin facturación electrónica ni pasarela.
- [Entidad Order](../src/Domain/Orders/Entity/Order.php): estado de pedido `open/cancelled`; estado de pago `pending/paid`; cumplimiento `reserved/released/consumed`; `totalAmountMinor` y moneda.
- [OrderController](../src/Http/Controller/OrderController.php): recursos de pedidos por sede y contexto jurídico; [OrdersManagement](../frontend/admin/OrdersManagement.tsx) consume las tres dimensiones y el total. **Ninguno de estos puntos acredita un cobro.**
- [FinanceManagement](../frontend/admin/FinanceManagement.tsx): exhibe registros descartables y conciliación ficticia, **no emite facturas ni ejecuta transferencias**; no reconvertirlos en facturación real.
- [Titularidad jurídica](arquitectura-entidades-legales.md): separar `tenant`, `LegalEntity` y sede; el mismo tenant puede contener dos titulares diferentes. No mezclar saldos por titular.
- [Guía operativa](modulos-operativos.md): pedidos y facturación integral aún en construcción. [datos.yml](../datos.yml) declara fase `construccion` y ausencia de datos reales.

**Separaciones obligatorias:** los estados existentes de `Order`, el registro de movimientos simulados y el estado de conciliación interno son conceptos distintos. Esta propuesta **no modifica** `Order.paymentStatus` y no marca `paid` a raíz de movimientos simulados. Una eventual confirmación real de pago necesitará una decisión y una integración futura independientes.

## 2. Alcance mínimo propuesto

1. **Registro interno sintético:** asociar a cada pedido uno o más movimientos declarados por un operador en entorno de prueba, sin conexión con terceros.
2. **Saldo y diferencias:** comparar el total inmutable del pedido con la suma neta de movimientos internos válidos y sus reversos compensatorios; permitir movimientos parciales solo si el negocio los habilita.
3. **Gestión de excepciones:** exponer exceso, reverso, pedido cancelado con registros, moneda distinta, discrepancias y registros tardíos como **casos a revisar**, nunca como ajustes silenciosos.
4. **Evidencia y permisos:** capturar actor, instante, motivo cuando corresponda, identificador opaco, origen simulado, idempotencia y entidad jurídica. La lectura/exportación también se filtra del lado servidor.
5. **Consulta interna:** vista por pedido/empresa/entidad y periodo, con distintivo fijo **«SIMULACIÓN — NO ACREDITA PAGO NI TIENE VALIDEZ FISCAL»**.

Se excluyen de esta entrega, aunque tengan espacio para futuros adaptadores: factura de venta, numeración autorizada, XML/UBL, eventos DIAN, impuestos y retenciones definitivos, notas crédito fiscales, conciliación bancaria, pasarela, webhooks de proveedores, transferencias, devoluciones reales, correos fiscales, recibos externos, contabilización automática y publicación productiva. Las puertas OWNER de [#1363](https://github.com/pl0n3r/Condor/issues/1363), [#1365](https://github.com/pl0n3r/Condor/issues/1365) y GO/NO-GO siguen independientes y cerradas.

## 3. Modelo conceptual y responsabilidades (no constituye migración)

| Concepto | Campos o reglas propuestas | Autoridad |
| --- | --- | --- |
| Pedido existente | id, tenant, entidad jurídica efectiva, sede/fuente permitida, moneda, total en unidades menores, order/payment/fulfillment status | Código de pedidos; no reescribir valores por conciliación |
| Movimiento interno simulado | id opaco, order_id, tenant_id, legal_entity_id, moneda, monto positivo en unidades menores, método descriptivo **simulado**, actor, timestamp, idempotency_key | Registro de prueba; **no** fuente bancaria ni fiscal |
| Reverso compensatorio | id nuevo, original_id, importe a revertir, actor, razón y momento; jamás borrar el original | Ajuste auditable, no devolución de dinero |
| Proyección de conciliación | order_id + tenant + titular jurídico + moneda, total, suma neta, saldo, diferencia, estado derivado, referencia a último cálculo | Vista interna derivada, no certificación externa |
| Evento de auditoría | tipo de acción, actor, recurso, transición, fecha, correlación; sin tokens ni números de cuentas | Sólo lectura por roles autorizados |

**Invariantes:** importes como enteros en unidad menor de la moneda del pedido (moneda por defecto COP), sin floats, conversiones automáticas ni sumas multi-moneda. Todo movimiento pertenece al mismo tenant, titular jurídico y pedido; no se asocia por similitud de cliente, sede o referencia bancaria. Validación de permisos y contexto jurídico del lado servidor, transacción e idempotencia para evitar registros duplicados. Un movimiento no se edita: su corrección es otro evento trazable. Un reverso no puede superar el saldo reversible y no puede reutilizar la misma clave. Si hay dos sociedades del mismo tenant, sus datos **no** se consolidan automáticamente.

**Dato sensible a evitar:** prohibido guardar PAN/CVV, tokens de pasarela, credenciales, cuentas bancarias completas, documentos de identidad o descripciones libres con PII. Las referencias de los fixtures deben ser opacas y no recuperables a identidades reales. Cualquier adopción de datos personales reales exige contrato de finalidad, minimización, retención, derechos, acceso, actualización de `datos.yml` y puertas legal/privacidad antes de construirlo o activarlo.

### Compatibilidad con pedidos anteriores a esta capacidad

Un pedido legacy con `payment_status=paid` no demuestra que exista un movimiento manual sintético ni una conciliación bancaria verificable. La futura proyección debe marcarlo `sin_evidencia_interna` o `no_conciliable` (denominación final sujeta al OWNER) hasta tener eventos propios; **nunca** inventar movimientos a partir del estado, generar importes ficticios por backfill o alterar sus pagos históricos. Si una versión futura requiere esquema nuevo, aplicar migración expand-compatible y tests de rollback/lectura de pedidos anteriores, sin tocar datos reales por esta propuesta.

## 4. Estados y transiciones propuestos — no aprobados aún

La fuente canónica de pedido conserva `open/cancelled` y la entrega `reserved/released/consumed`. El campo existente de pago conserva por ahora `pending/paid`; **no se crean transiciones a `paid` desde la simulación**.

Los siguientes nombres son de **estado de conciliación calculado** y no sustituyen `Order.paymentStatus`:

| Estado derivado | Condición sintética | Operación |
| --- | --- | --- |
| `sin_registros` | Ningún importe neto interno; sin reversos o sin movimientos | Mostrar saldo total pendiente de registro |
| `parcial_simulado` | 0 < neto < total | Mostrar saldo y permitir otro registro autorizado sólo si parcial está habilitado |
| `cuadrado_interno_simulado` | neto = total y no hay excepciones | Mostrar coincidencia aritmética **sin afirmar pago** |
| `diferencia_a_revisar` | neto > total, moneda inválida, pedido cancelado con movimientos, evidencia contradictoria o regla vulnerada | Cola de revisión; no distribuir sobrante automáticamente |
| `reversado_total` | Existen movimientos con reversos equivalentes, neto = 0 | Conservar historial y saldo íntegro sin borrar evidencia |

Cada registro inicia con estado interno `registrado_simulado`; un reverso compensatorio lo neutraliza en la suma, sin sustituir ni borrar el evento original. No hay estado `confirmado_por_banco` en la primera versión.

**Reglas de cálculo:**
- `neto_registrado_minor = suma(importes internos) - suma(reversos válidos)`.
- `saldo_minor = max(total_pedido_minor - neto_registrado_minor, 0)`.
- `exceso_minor = max(neto_registrado_minor - total_pedido_minor, 0)`.
- No recalcular el precio del pedido a partir de un pago; una anulación de pedido no dispara reembolso.
- Pedido cancelado con neto > 0 queda `diferencia_a_revisar`; cumplimiento de inventario sigue su servicio propio.
- Para totales cero se requiere decisión explícita: sin movimientos se identifica como `sin_registros`, no como pago real.
- Referencia repetida/idempotency_key repetida con cuerpo distinto se rechaza; misma solicitud idéntica devuelve el resultado original.
- No se propagan importes a otras órdenes ni se compensan saldos entre entidades jurídicas o divisas.
- Cualquier estado desconocido o evidencia inválida falla cerrado, no se interpreta como `paid`.

**Ejemplo exclusivamente sintético** (moneda y valores en unidades menores, no en pesos exhibidos): pedido por 100000; primer movimiento 40000 => saldo 60000 (`parcial_simulado`); segundo 60000 => saldo 0 (`cuadrado_interno_simulado`). Si se revierte el segundo, neto 40000 y saldo 60000 con historial íntegro. Un tercer movimiento 70000 produciría exceso 10000 y excepción, no devolución ni crédito cruzado.

## 5. Permisos, eventos y UX mínimos

- Visualización: permiso de pedidos/finanzas y ámbito tenant + `LegalEntity` + sedes autorizadas, **resueltos de nuevo en backend**. Un propietario de un tenant no obtiene acceso transversal de otra entidad por compartir cuenta.
- Registro/corrección: permisos de escritura financieros u operativos explícitos, con **separación de funciones propuesta** (quien registró no debe aprobar su propia excepción; requiere decisión OWNER).
- Auditoría: evento append-only por creación, rechazo, reverso y cierre de incidencia; actor, fecha, recurso y motivo, sin secretos.
- UI administrativa: inicialmente revelar total, importe interno, saldo, estado, referencia y etiqueta «simulado»; al abrir detalle mostrar historial, motivo y permisos. En errores/403 no filtrar totales o identificadores ajenos. Cumplir loading/empty/error/permission-denied y accesibilidad.
- Exportación: para desarrollo, sólo fixtures sin PII, con scope/permissions iguales a la API; ningún CSV puede presentarse como factura.

## 6. Elecciones que deberá aprobar/corregir el dueño en el PR

| Decisión | Opción A (predeterminada **propuesta**, no aprobada) | Opción B | Impacto |
| --- | --- | --- | --- |
| E1. Pagos parciales | Admitir movimientos internos parciales por tenant, apagados por defecto | Exigir un único registro por el total | Complejidad de saldo y conciliación |
| E2. Métodos simulados | Etiquetas predefinidas «efectivo», «transferencia», «otro» **solo como texto de simulación**, sin datos bancarios | Una etiqueta genérica «registro interno» | UX y taxonomía; no valida dinero |
| E3. Excesos | Rechazar altas que excedan total, dejando evento de rechazo | Registrar exceso y enviarlo a revisión, sin asignación automática | Auditoría vs facilidad de corrección |
| E4. Revisión de diferencias | Rol revisor separado del creador | Permitir al mismo rol con motivo obligatorio y auditoría | Segregación de funciones |
| E5. Registro al cancelar pedido | Mantener registros inmutables y abrir excepción; jamás reembolso implícito | Impedir nuevos registros en pedido cancelado | Control y recuperación |
| E6. Cobro real y fiscal | **Continuar inhabilitados** hasta decisión separada y gates de #1363/#1365 | Solo replantearlo en el futuro con proveedor y revisión legal | Riesgo jurídico y operacional |

Una aprobación expresa del PR debe identificar las elecciones E1–E6 o indicar correcciones. La autorización anterior del dueño para **redactar** la propuesta no seleccionó estas opciones ni cerró AC-01. Hasta entonces los issues derivados se mantienen `bloqueado`, sin implementación y sin despliegues.

## 7. Desglose verificable en Issues (AC-02)

Los siguientes issues ya se crearon **bloqueados** y clasificados en el acto de creación; no heredan la reserva/claim documental de #1513:

1. [#1921 — Movimientos internos simulados, auditoría y aislamiento](https://github.com/pl0n3r/Condor/issues/1921). Una vez aprobados E1/E2/E5: persistencia segura, reversos idempotentes, validación de tenant + entidad y pruebas negativas. Nunca marcar pago real.
2. [#1922 — Conciliación interna por pedido y diferencias](https://github.com/pl0n3r/Condor/issues/1922). Depende del contrato ejecutable de #1921 y de las decisiones E1/E3/E5: proyección neta, parcial/exacto/exceso, concurrencia, cancelaciones y pruebas multi-moneda/multi-entidad.
3. [#1923 — Vista, permisos, exportación simulada y privacidad](https://github.com/pl0n3r/Condor/issues/1923). Depende de #1921 y #1922, decisión E4 y revisión de privacidad: superficie admin, audit trail, mensajes inequívocos, tests de fuga de datos y accesibilidad.

Cada hoja futura requiere antes de empezar: aprobación OWNER explícita de la especificación, plan task/claims de **archivos concretos sin solapamiento**, reserva V3 propia, tests exact-HEAD y CI canónico; ninguna depende de una decisión automática a nombre del dueño. Las integraciones reales tendrán issues/gates aparte solo tras decisiones humanas y compliance.

## 8. Matriz de aceptación y regresión propuestas

| Caso sobre fixtures descartables | Resultado esperado |
| --- | --- |
| Sin movimientos; registro parcial, exacto y reverso | Saldos derivados correctos, `payment_status` no cambia automáticamente |
| Total > 0, exceso y anulación de pedido | Diferencia en revisión; sin reembolso ni crédito |
| Evento duplicado y dos llamadas concurrentes | Idempotencia y suma sin doble contabilización |
| Intentar leer/registrar en otra empresa o entidad del mismo tenant | 403/404 según contrato vigente, sin cambios ni fuga de total |
| Movimiento de distinta moneda/sede/orden | Rechazo y auditoría, sin conciliación transversal |
| Monto negativo/cero, reverso repetido o superior al original | Rechazo o tratamiento explícito para total cero, sin sumas corruptas |
| Exportar sin permiso o desde tenant ajeno | Denegación, sin CSV ni filtración |
| Solicitar factura/nota crédito/DIAN o transacción de dinero | Capacidad no implementada; ninguna llamada a tercero |
| CI / escaneo de seguridad para cada PR derivado | Requeridos sobre su HEAD exacto; no equivalen a producción |

## 9. Riesgos fiscales, legales, privacidad y rollback (AC-03)

| Riesgo | Control y condición de reentrada |
| --- | --- |
| Presentar un registro interno como factura o comprobante de pago | Terminología y marca «simulación sin validez fiscal» en API/UI/export; sin numeración, XML, CFDI/DIAN o firma. Integración fiscal futura requiere definición legal/proveedor y decisión OWNER |
| Falsa conciliación bancaria | No consumir extractos, tokens, notificaciones de bancos ni pasarelas; diferenciar aritmética interna de confirmación externa |
| Retenciones, impuestos, diferencias de moneda/centavos | Importes enteros, moneda explícita, sin liquidación fiscal ni conversiones; políticas fiscales revisadas antes de live |
| Datos personales y financieros | Fixtures sintéticos; privacidad por diseño, límites de campos y retención. Antes de PII real actualizar `datos.yml`, bases legales, finalidad, derechos, proveedores y controles aplicables |
| Fuga entre empresas y sociedades del mismo tenant | AuthZ servidor por tenant + entidad jurídica + sede; pruebas negativas y aislamiento también en export y métricas agregadas |
| Fraude o manipulación de movimientos internos | Auditoría append-only, idempotencia, motivo/reverso, roles mínimos y revisión de excepciones |
| Cierre aparente por CI verde | Puertas humanas D-043/D-059 y evidencia de producción independientes: sin live no se presume validación; no ejecutar deploy/cobros reales |

**Reversión de esta entrega #1513:** revertir el PR documental o cerrar las hojas posteriores no modifica datos, runtime ni pagos. Los cambios técnicos futuros deberán tener migraciones compatibles y plan de reversión propios, nunca una eliminación destructiva de historial auditable.

## 10. Estado de los criterios del issue original

- **AC-01 — PENDIENTE DEL OWNER.** La especificación es una **propuesta**; el dueño aprobó su redacción y revisará/aprobará o corregirá el PR.
- **AC-02 — ENTREGADO COMO PROPUESTA.** Se crearon #1921, #1922 y #1923 con criterios verificables y dependencias claras, todos bloqueados hasta AC-01.
- **AC-03 — DOCUMENTADO.** Riesgos fiscales, separación entre cobro declarado/real, privacidad, aislamiento y gates de proveedores están enumerados arriba.
- **Sin activación:** no se solicita proveedor fiscal, cuenta bancaria, DIAN, facturación real, ingreso de PII ni lanzamiento productivo desde esta especificación.

**Referencias:** [#1513](https://github.com/pl0n3r/Condor/issues/1513) · [#1363](https://github.com/pl0n3r/Condor/issues/1363) · [#1365](https://github.com/pl0n3r/Condor/issues/1365) · [#1921](https://github.com/pl0n3r/Condor/issues/1921) · [#1922](https://github.com/pl0n3r/Condor/issues/1922) · [#1923](https://github.com/pl0n3r/Condor/issues/1923).
