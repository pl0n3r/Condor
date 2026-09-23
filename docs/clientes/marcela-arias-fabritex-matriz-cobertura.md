# Marcela Arias / Fabritex — matriz de cobertura y generalización en Condor

**Documento complementario:** [requerimientos funcionales RF-001…RF-129](./marcela-arias-fabritex-requerimientos-funcionales.md)  
**Trazabilidad:** Issue #175 / PR #176  
**Base analizada:** `main` V 0.1.20 (`1f8b22625f28de64c591c8083098c093e796e673`) y frentes abiertos conocidos al 2026-09-22.

## 1. Propósito

Este documento responde dos preguntas distintas:

1. **¿Qué parte de los 129 requerimientos del primer cliente ya existe, está definida o está en construcción en Condor?**
2. **¿Cómo incorporar las brechas sin convertir Condor en un Frankenstein ni en software hecho a la medida de Marcela Arias / Fabritex?**

La regla de producto es deliberada:

> Un cliente real puede descubrir capacidades que Condor necesita, pero no debe dictar una bifurcación de arquitectura. Cada necesidad se traduce primero a un concepto de negocio general, luego se decide si pertenece al núcleo, a un módulo opcional, a configuración, a una plantilla vertical o a un adaptador de integración.

Los nombres **Marcela Arias**, **Fabritex**, **TikTok**, el modelo específico de huellero y cualquier flujo particular del cliente son datos/configuración/integraciones; no deben aparecer como condicionales de dominio del tipo `if tenant == ...`.

---

## 2. Lenguaje de cobertura

| Estado | Significado |
| --- | --- |
| **MAIN** | Existe una capacidad usable en `main`; puede requerir extensión para cubrir el RF completo. |
| **EN CURSO** | Hay implementación activa fuera de `main`; no se considera entregada todavía. |
| **DEFINIDO** | La decisión/modelo ya existe en especificaciones o roadmap, pero falta implementación suficiente. |
| **NUEVO** | El requerimiento revela una capacidad reusable aún no cubierta como dominio de producto. |
| **ADAPTADOR** | La capacidad general debe vivir en Condor, pero la conexión con hardware/proveedor/canal concreto se implementa mediante un adaptador. |
| **PRINCIPIO** | Requisito transversal/no funcional que debe gobernar todos los módulos. |

### Formas de generalización

- **Núcleo:** concepto transversal obligatorio para mantener coherencia del SaaS.
- **Módulo reusable:** capacidad de negocio instalable/activable sin acoplarla a una industria.
- **Configuración:** variación esperable entre empresas; debe resolverse como datos, reglas o políticas.
- **Plantilla vertical:** configuración inicial para un sector; acelera onboarding pero no crea un fork.
- **Adaptador:** integración externa reemplazable detrás de un contrato estable.
- **Analítica derivada:** indicadores calculados sobre eventos/datos de módulos fuente; no duplica su lógica.

---

## 3. Qué confirma este primer cliente sobre Condor

El cliente confirma varias decisiones que Condor ya había tomado antes de conocer estos RF:

- **Tenant no equivale a razón social.** `LegalEntity` ya existe en `main`. La decisión de arquitectura #177 establece tenant como grupo/cuenta y entidad legal como titular jurídico: un tenant puede tener varias razones sociales, sin que existir `LegalEntity` pruebe ya aislamiento operativo. Se debe confirmar con el cliente la asignación de empresas, usuarios, sedes y permisos; el soporte operativo cross-entity sigue pendiente.
- **Producto y variante son conceptos separados.** El catálogo ya modela `Product` y `ProductVariant`.
- **Sede y fuente de inventario son conceptos diferentes.** La especificación ya lo define y el slice de inventario #171/#172 lo está materializando.
- **Usuario no equivale a empleado ni a cliente.** Identidad, membresías, roles y asignaciones por sede ya están separados del dominio comercial.
- **Roles/permisos deben ser configurables.** Ya existe el núcleo de permisos y roles por sede.
- **Storefront no debe ser otra aplicación por cliente.** Existe storefront tenant-owned con dominio configurable.
- **Auditoría es transversal.** `AuditEvent` y `PlatformAuditEvent` ya existen; los nuevos módulos deben emitir trazabilidad de negocio.
- **Backup/restauración son capacidades de plataforma, no requisitos exclusivos del cliente.** V 0.1.20 ya incorpora backup/restore verificable.

El mayor aprendizaje no es “hacer un ERP textil”, sino ampliar Condor desde su frente comercial hacia una **plataforma operativa modular** capaz de cubrir fabricación, abastecimiento, costos y personas cuando un tenant active esos módulos.

---

## 4. Arquitectura anti-Frankenstein

### 4.1 Capas funcionales objetivo

**Núcleo transversal de Condor**

- Tenant / organización.
- Entidad legal / razón social.
- Sede.
- Identidad, membresías, roles y permisos.
- Auditoría.
- Archivos/media.
- Configuración y capacidades activas.
- Notificaciones/alertas.
- Búsqueda.
- Exportación.
- Eventos internos y contratos entre módulos.

**Suite comercial**

- Catálogo.
- Variantes.
- Precios y reglas comerciales.
- Inventario.
- Clientes.
- Canales.
- Storefront/CMS.
- Pedidos/ventas.
- Cotizaciones.
- Analítica comercial.

**Suite de operaciones**

- Abastecimiento/proveedores/compras.
- Materiales/componentes y unidades de medida.
- Producción/manufactura.
- BOM/recetas/fichas técnicas.
- Calidad, reproceso, desperdicio y merma.
- Costeo.
- Trazabilidad de lotes/órdenes.

**Suite de personas**

- Empleado.
- Organización laboral/cargo/área.
- Documentos y novedades.
- Asistencia/turnos.
- Integraciones biométricas.

No todos los tenants deben ver ni pagar por todos los módulos.

### 4.2 Regla de variabilidad

Una diferencia entre clientes debe resolverse en este orden:

1. **Configuración**, si cambia una política o valor.
2. **Workflow/regla**, si cambia una secuencia operativa.
3. **Capacidad/módulo opcional**, si cambia el dominio usado.
4. **Adaptador**, si cambia un tercero/hardware.
5. **Código nuevo de núcleo**, solo si existe una invariante reusable que no cabe correctamente en las anteriores.

Nunca crear:

- un módulo “Inventario Marcela Arias” y otro “Inventario Fabritex”;
- una tabla por cliente;
- una aplicación/motor web duplicado por razón social. RF-055 y RF-060 sí requieren **dos experiencias públicas diferenciadas**, configurables por marca, canal y dominio sobre un motor común; no prohibir esas dos páginas;
- permisos codificados por nombre de cargo;
- una integración de huellero mezclada con el dominio de asistencia;
- lógica de producción limitada a prendas, telas o tallas.

### 4.3 Plantillas verticales

Condor sí puede ofrecer una **plantilla Confección / Textil**, compuesta por configuración inicial como:

- unidades de medida frecuentes;
- atributos talla/color;
- categorías de materia prima;
- motivos de merma;
- tipos de procesos;
- KPIs sugeridos;
- roles iniciales;
- estados/workflows;
- formatos de etiqueta.

La plantilla acelera la implantación, pero todos esos elementos continúan siendo datos configurables sobre módulos genéricos.

---

# 5. Matriz RF → Condor

## 5.1 Gestión de empresas — RF-001 a RF-004

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-001 | **PARCIAL / MAIN** | `Tenant` + `LegalEntity` existen. #177 fija la arquitectura reusable: varias razones sociales del mismo grupo dentro de un tenant, con titularidad y permisos por entidad legal; verificar con el cliente su estructura concreta sin hardcodear sociedades. Tener `LegalEntity` no demuestra separación funcional terminada. |
| RF-002 | **DEFINIDO (#177)** | Exigir `legal_entity_id` efectivo para inventario, ventas, costos y reportes con titularidad jurídica; demostrar aislamiento de consultas, escrituras, exportaciones y permisos por entidad dentro de un tenant. Tenant, sede y fuente no sustituyen a la entidad legal. |
| RF-003 | **NUEVO (#177)** | Modelar operación intercompany entre entidades legales con origen/destino, titularidad, documentos, precio/valoración y trazabilidad acordes al caso. Una transferencia física entre fuentes solo es interna si ambas tienen la misma entidad legal; la operación interempresa requiere un flujo distinto y explícito. |
| RF-004 | **PARCIAL / MAIN** | Reusar identidad y contexto del tenant; selector de entidad legal solo si hay varias, oculto para single-entity. Cambiar el contexto no confiere permisos ni modifica el aislamiento en API, reportes o exportaciones. La UI y autorización cross-entity aún deben implementarse y probarse. |

## 5.2 Inventario de producto terminado — RF-005 a RF-014

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-005 | **EN CURSO** | El slice #171/#172 crea el núcleo de inventario. Producto terminado es un uso del inventario, no un inventario distinto. |
| RF-006 | **PARCIAL / MAIN** | Producto/variante ya existen; costo, precios, stock y barcode se mantienen como capacidades relacionadas, no campos monolíticos obligatorios del producto. |
| RF-007 | **EN CURSO** | Existencia por variante + fuente de inventario. Talla/color son atributos de variante, no columnas fijas universales. |
| RF-008 | **DEFINIDO** | Pedido/venta debe producir reserva/consumo de inventario mediante servicio transaccional e idempotente. |
| RF-009 | **DEFINIDO** | Canal comercial como entidad configurable: tienda, web, social commerce, marketplace, B2B, etc. “TikTok” es una instancia/configuración de canal. |
| RF-010 | **EN CURSO + MAIN** | Ajuste de inventario protegido por permisos existentes `inventory.*`; nunca mutación directa de saldo. |
| RF-011 | **EN CURSO** | Todo ajuste genera movimiento inmutable con actor, instante, cantidad, referencia y motivo configurable. |
| RF-012 | **NUEVO** | Política reusable de reposición/alerta por producto/fuente: mínimo, máximo, punto de reposición y destinatarios. |
| RF-013 | **NUEVO** | Analítica derivada de inventario: agotado, bajo stock, rotación y aging. No guardar “alta rotación” como estado manual. |
| RF-014 | **NUEVO** | Subcapacidad **conteo físico/ciclo de inventario**: sesión de conteo, captura, conciliación y ajuste auditado. |

## 5.3 Materias primas e insumos — RF-015 a RF-024

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-015 | **PARCIAL** | Extender el concepto stockable para soportar materiales/componentes además de producto vendible; no crear “inventario Fabritex”. |
| RF-016 | **NUEVO** | Catálogo reusable de **materiales/componentes/insumos** con categorías configurables. Tela, cierre o hilo son datos de una plantilla textil. |
| RF-017 | **NUEVO** | Núcleo de **unidades de medida** y conversiones controladas: unidad, kg, m, rollo, etc. |
| RF-018 | **NUEVO** | Entrada de material por recepción de compra, devolución u otro motivo tipificado. |
| RF-019 | **NUEVO** | Consumo de materiales originado por producción u otros movimientos autorizados. |
| RF-020 | **NUEVO** | Tipología reusable de pérdidas/daños/mermas, vinculable a inventario, producción y calidad. |
| RF-021 | **PARCIAL / MAIN** | Auditoría transversal ya existe; cada movimiento de stock debe conservar actor/tiempo/motivo de negocio. |
| RF-022 | **EN CURSO** | Saldo actual por ítem stockable + fuente. “Tiempo real” significa lectura consistente del ledger/saldo vigente, no un proceso paralelo. |
| RF-023 | **NUEVO** | Mismo motor de alertas de stock; no un sistema separado para materias primas. |
| RF-024 | **NUEVO** | Política configurable min/max por ítem/fuente y, cuando aplique, por periodo o proveedor. |

## 5.4 Producción — RF-025 a RF-034

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-025 | **NUEVO** | Módulo reusable **Manufacturing/Producción**, opcional por tenant. |
| RF-026 | **NUEVO** | `ProductionOrder` genérica; no restringida a confección. |
| RF-027 | **NUEVO** | Modelo de orden con output, cantidad planificada/real, responsable, materiales, mermas, defectos y estado. |
| RF-028 | **NUEVO** | Consumo transaccional de inventario desde producción usando movimientos del módulo Inventory. Producción no modifica saldos directamente. |
| RF-029 | **NUEVO** | Recepción de outputs terminados mediante el mismo contrato de Inventory. |
| RF-030 | **NUEVO** | **BOM/receta/ficha técnica** versionada para consumo teórico por unidad. |
| RF-031 | **NUEVO** | Comparación plan/real sobre BOM versionada y consumos reales. |
| RF-032 | **NUEVO** | Analítica de desviación de producción con umbrales configurables. |
| RF-033 | **NUEVO** | Calidad/reproceso/scrap como resultados tipificados de la orden, con movimientos de inventario cuando corresponda. |
| RF-034 | **NUEVO** | Genealogía/trazabilidad: materiales/lotes → consumos → orden → outputs/lotes. Debe soportar manufactura más allá de prendas. |

## 5.5 Costos de producción — RF-035 a RF-043

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-035 | **NUEVO** | Módulo **Costing** separado de Inventory, consumiendo eventos/valuaciones de inventario y producción. |
| RF-036 | **NUEVO** | Costeo por producto/variante/output con método de costeo explícito y versionado. |
| RF-037 | **NUEVO** | Componentes de costo configurables; materiales, mano de obra, terceros y overhead son categorías, no columnas rígidas. |
| RF-038 | **NUEVO** | Snapshot de costo unitario calculado para cada corrida/versión. |
| RF-039 | **NUEVO** | Histórico temporal de costos; nunca sobrescribir el pasado al actualizar una ficha. |
| RF-040 | **DEFINIDO** | El futuro motor de Pricing puede usar costo como input, sin acoplar precio = costo×fórmula obligatoria. |
| RF-041 | **DEFINIDO** | Listas/reglas de precios ya están en el modelo de producto pendiente; costo no debe confundirse con precio comercial. |
| RF-042 | **NUEVO** | Margen/utilidad como analítica calculada sobre precio efectivo y costo aplicable/versionado. |
| RF-043 | **MAIN / PRINCIPIO** | Permisos por módulo/acción ya existen; Costing/Analytics debe declarar permisos sensibles específicos. |

## 5.6 Códigos de barras — RF-044 a RF-047

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-044 | **NUEVO** | Capacidad reusable de **identificadores escaneables**; soportar EAN/UPC/Code128/identificador interno según política. |
| RF-045 | **PARCIAL / MAIN** | El código debe apuntar a la unidad operable correcta —normalmente variante— y no codificar talla/color por separado. |
| RF-046 | **NUEVO** | Plantillas de etiqueta + servicio de render/impresión; impresora concreta mediante adaptador cuando haga falta. |
| RF-047 | **NUEVO** | Resolver scan → entidad/acción mediante un servicio único reutilizable por POS, conteos, movimientos y consultas. |

## 5.7 Ventas — RF-048 a RF-054

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-048 | **DEFINIDO** | Un único dominio Orders/Sales con `channel_id`; no una tabla/flujo por canal. |
| RF-049 | **DEFINIDO** | Efecto de stock gobernado por estados y política de reserva/consumo, no por “guardar venta”. |
| RF-050 | **DEFINIDO** | Canal de origen obligatorio cuando el contexto comercial lo requiera. |
| RF-051 | **DEFINIDO** | Mayorista/detal se expresa mediante categoría comercial, lista de precios y reglas, no mediante dos motores de venta. |
| RF-052 | **NUEVO / ANALÍTICA** | Read models/reporting sobre dimensiones fecha, canal, producto, categoría, cliente y vendedor. |
| RF-053 | **NUEVO / ANALÍTICA** | Ranking derivado de líneas de pedido/venta; no campo persistido en Product. |
| RF-054 | **NUEVO / ANALÍTICA** | Rentabilidad combina ventas + costos históricos aplicables; requiere Costing. |

## 5.8 Página web / e-commerce Marcela Arias — RF-055 a RF-059

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-055 | **PARCIAL / MAIN** | Storefront tenant-owned y dominio personalizado ya existen; falta e-commerce completo. La página de esta marca debe conservar identidad visual y catálogo propios, asociados a su entidad legal y canal dentro del tenant-grupo definido por #177, sin duplicar la plataforma. Los permisos y datos por entidad siguen sin implementarse plenamente. |
| RF-056 | **DEFINIDO** | Storefront consume el mismo catálogo/inventario; nunca sincronización por copia de bases de datos. |
| RF-057 | **DEFINIDO** | Checkout crea pedido y aplica la política transaccional de inventario. |
| RF-058 | **DEFINIDO** | Disponibilidad usa la fuente efectiva y el servidor impide vender más unidades que el stock disponible en la página de Marcela Arias: **backorder desactivado para ese canal** según este RF. El motor conserva una política configurable para otros canales/tenants cuando sus requisitos lo permitan. |
| RF-059 | **DEFINIDO** | Pedido web entra al mismo dominio Orders que tienda física/B2B; cambia el canal, no el modelo. |

## 5.9 Página web B2B Fabritex — RF-060 a RF-063

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-060 | **PARCIAL / MAIN** | Exponer una segunda página B2B diferenciada en contenido, dominio y acceso mediante configuración por marca, canal y entidad legal dentro del tenant-grupo establecido en #177; validar la asignación concreta del cliente sin proponer otro motor web ni una topología alternativa por defecto. |
| RF-061 | **PARCIAL** | Catálogo + servicios + CMS/bloques configurables. Productos y servicios comparten contratos comerciales donde sea razonable. |
| RF-062 | **DEFINIDO** | Solicitud web se modela como lead, cotización o pedido según tipo, siempre dentro de Condor. |
| RF-063 | **NUEVO** | Módulo/capacidad reusable **Quotes/Cotizaciones**, convertible a pedido y apto para B2B/institucional. |

## 5.10 Clientes mayoristas y detal — RF-064 a RF-069

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-064 | **DEFINIDO** | `Customer` con categoría comercial principal configurable. |
| RF-065 | **DEFINIDO** | Separar Customer de User; una cuenta de portal puede vincularse a un cliente sin fusionar ambas entidades. |
| RF-066 | **DEFINIDO** | Autorización comercial + lista de precios efectiva; no campo booleano global `is_wholesale` como única estrategia. |
| RF-067 | **DEFINIDO** | Precio retail como lista/default del canal y contexto. |
| RF-068 | **NUEVO** | Workflow de aprobación/cambio de categoría comercial, auditable y configurable. |
| RF-069 | **DEFINIDO** | Historial deriva de pedidos/ventas relacionados con Customer. |

## 5.11 Banco de fotos y productos — RF-070 a RF-073

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-070 | **NUEVO** | Módulo transversal **Media/Assets** tenant-owned. |
| RF-071 | **NUEVO** | Relaciones tipadas Media↔Product/Variant/otros recursos. |
| RF-072 | **NUEVO** | Colecciones ordenables de assets, metadata, alt text y estado/publicación. |
| RF-073 | **NUEVO** | Reutilizar el mismo asset en storefront, catálogo B2B, exportaciones y futuros canales sin duplicar archivos. |

## 5.12 Pedidos — RF-074 a RF-078

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-074 | **DEFINIDO** | Dominio Orders pendiente de implementación. |
| RF-075 | **DEFINIDO** | Condor ya decidió separar estado de pedido, pago y cumplimiento. Los nombres visibles/etapas pueden configurarse sin romper estados semánticos internos. |
| RF-076 | **DEFINIDO** | Pedido referencia Customer opcional/obligatorio según política, vendedor, canal y líneas. |
| RF-077 | **DEFINIDO** | Política configurable de reserva/consumo de stock; inventario sigue siendo dueño de sus mutaciones. |
| RF-078 | **DEFINIDO + MAIN** | Historial de transición/eventos de pedido + auditoría transversal; nunca update destructivo sin rastro. |

## 5.13 Clientes — RF-079 a RF-082

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-079 | **DEFINIDO** | Módulo Customer/CRM comercial básico tenant-owned. |
| RF-080 | **DEFINIDO** | Perfil comercial con contactos, categoría, observaciones y relaciones; PII protegida por permisos. |
| RF-081 | **NUEVO / ANALÍTICA** | Compras acumuladas como métrica derivada; permitir moneda/periodo/contexto claros. |
| RF-082 | **NUEVO / ANALÍTICA** | Segmentos calculados o reglas configurables: frecuente, inactivo, mayorista, etc. |

## 5.14 Proveedores y compras — RF-083 a RF-088

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-083 | **NUEVO** | Módulo **Procurement** con Supplier separado de Customer aunque una contraparte pueda desempeñar ambos roles. |
| RF-084 | **NUEVO** | Historial deriva de órdenes/recepciones/compras al proveedor. |
| RF-085 | **NUEVO** | Purchase Order / Purchase Receipt para materiales, productos u otros ítems comprables. |
| RF-086 | **NUEVO** | Recepción confirmada genera movimiento de entrada en Inventory; Procurement no altera saldos por fuera del contrato. |
| RF-087 | **NUEVO** | Precio de compra histórico por proveedor/ítem/fecha/unidad/moneda. |
| RF-088 | **NUEVO / ANALÍTICA** | Comparador temporal y entre proveedores sobre datos normalizados de compra. |

## 5.15 Recursos humanos — RF-089 a RF-093

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-089 | **NUEVO** | Módulo opcional **People/HR**, separado de Identity. |
| RF-090 | **NUEVO** | `Employee` como persona laboral vinculable opcionalmente a `User`; no todo empleado debe tener login y no todo user debe ser empleado. |
| RF-091 | **NUEVO** | Perfil laboral + documentos protegidos + historial temporal de relación/cargo cuando se necesite. |
| RF-092 | **NUEVO** | Novedades/eventos laborales tipificados y auditables. |
| RF-093 | **PARCIAL / MAIN** | Roles/permisos ya existen; cargo puede sugerir una plantilla de roles, pero jamás conceder privilegios implícitos por string de cargo. |

## 5.16 Asistencia y huellero — RF-094 a RF-098

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-094 | **ADAPTADOR** | Definir contrato `AttendanceDeviceAdapter`/importación; cada marca/protocolo de huellero es plugin/adaptador. Por defecto Condor ingiere solo eventos mínimos de asistencia y no almacena plantillas/imágenes biométricas. |
| RF-095 | **NUEVO** | Módulo Time & Attendance con marcaciones crudas inmutables y jornadas derivadas. |
| RF-096 | **NUEVO** | Reglas configurables de horarios, tolerancias, tardanzas, ausencias y excepciones. |
| RF-097 | **NUEVO / ANALÍTICA** | Reportes por empleado/periodo derivados de marcaciones y reglas vigentes. |
| RF-098 | **NUEVO** | Workflow de justificación/aprobación conservando evento original y corrección/decisión separadas. |

## 5.17 Usuarios, roles y permisos — RF-099 a RF-104

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-099 | **MAIN** | `User` individual ya existe; compartir credenciales contradice el modelo. |
| RF-100 | **MAIN** | Roles tenant-owned y asignaciones por sede ya implementados. |
| RF-101 | **MAIN** | Catálogo de permisos por módulo/acción existe y debe ampliarse con cada módulo nuevo. |
| RF-102 | **MAIN / PRINCIPIO** | Autorización server-side; los futuros módulos financieros/costos/analytics deberán declarar permisos granulares. |
| RF-103 | **MAIN** | Perfiles ampliados se modelan con roles/permisos; no roles mágicos por nombre excepto invariantes de plataforma. |
| RF-104 | **PARCIAL / MAIN** | Alta/invitación/bloqueo se apoyan en Identity. Para información histórica, “eliminar acceso” debe privilegiar desactivar/revocar frente a borrar identidad referenciada. |

## 5.18 Auditoría y trazabilidad — RF-105 a RF-108

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-105 | **MAIN** | Existen entidades de auditoría de tenant y plataforma. Cada dominio nuevo debe emitir eventos útiles, no logs narrativos. |
| RF-106 | **PARCIAL / MAIN** | Estándar mínimo: actor, acción/evento, timestamp, recurso/contexto y cambio relevante con minimización de PII. |
| RF-107 | **PARCIAL / MAIN + PRINCIPIO** | `AuditEvent` no expone mutadores, pero su FK de tenant y la migración usan `ON DELETE CASCADE`: borrar un tenant borra esos eventos. Antes de afirmar retención/inmutabilidad completa, definir y aplicar protección del historial frente al borrado de cuenta (por ejemplo cierre lógico, archivo sujeto a retención y política de eliminación autorizada); correcciones de negocio se representan como eventos posteriores. |
| RF-108 | **PARCIAL** | Falta una vista/read model de auditoría transversal para inventario, producción, costos, pedidos, etc.; se construye sobre el mismo ledger de auditoría. |

## 5.19 Panel gerencial — RF-109 a RF-114

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-109 | **PARCIAL / MAIN** | Existe centro de control de plataforma, no todavía un dashboard gerencial de negocio por tenant. Crear Analytics/Dashboard tenant-owned. |
| RF-110 | **PRINCIPIO** | KPIs con jerarquía clara, drill-down y estados de datos; evitar dashboard decorativo. |
| RF-111 | **NUEVO** | Dimensión/selector por entidad legal con agregado “todas”, respetando permisos y moneda/contexto. |
| RF-112 | **NUEVO / ANALÍTICA** | Catálogo de KPIs componible. Cada KPI declara fuente, definición, periodo, scope y permisos; TikTok/tienda/web son valores de dimensión canal. |
| RF-113 | **NUEVO** | Alertas accionables enlazadas a la entidad que las originó; no duplicar reglas dentro del dashboard. |
| RF-114 | **NUEVO / ANALÍTICA** | Filtros dimensionales comunes y contratos de consulta reutilizables. |

## 5.20 Alertas — RF-115 a RF-117

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-115 | **PARCIAL / MAIN** | Existe dominio Notification; falta motor de condiciones/eventos de negocio. |
| RF-116 | **NUEVO** | Motor reusable de reglas/alertas: evento + condición + severidad + destinatarios + deduplicación + resolución. |
| RF-117 | **NUEVO** | Centro de alertas dentro del Admin; otros canales de notificación pueden añadirse como adaptadores. |

## 5.21 Reportes — RF-118 a RF-121

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-118 | **DEFINIDO** | Analytics/reporting ya está en el mapa de dominios, falta motor funcional. |
| RF-119 | **NUEVO** | Contrato común de filtros, paginación, scope y periodos. |
| RF-120 | **NUEVO** | Export jobs reutilizables a XLSX/PDF/CSV con permisos, límites y trazabilidad; no exportadores artesanales por pantalla. |
| RF-121 | **NUEVO** | Reportes compuestos consumen read models de dominios; no replican lógica de inventario/ventas/producción. |

## 5.22 Seguridad y respaldo — RF-122 a RF-125

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-122 | **PARCIAL / MAIN** | V 0.1.20 entrega script con retención configurable y ensayo CI sobre MariaDB descartable, pero no prueba ejecución productiva periódica. Baseline D-040: respaldo **diario** de MariaDB; media/archivos incluidos o replicación equivalente; al menos **30 días** de puntos de recuperación cuando el hosting lo permita; copia fuera del mismo punto de fallo con datos reales, cifrado según soporte y acceso mínimo. Exigir evidencia read-only de cron autorizado, último respaldo, integridad, destino y alertas, sin publicar datos. |
| RF-123 | **MAIN / PRINCIPIO** | Autenticación, autorización server-side, CSRF, aislamiento tenant, headers y manejo seguro de secretos son baseline. |
| RF-124 | **PARCIAL / MAIN** | El restore en CI demuestra mecanismo, no recuperación real. Baseline D-040: **RPO ≤24 h**, **RTO ≤8 h**, ensayos de restauración **trimestrales** en entorno aislado con datos reales autorizados una vez existan, y después de cambios relevantes; comprobar DB, archivos y configuración, registrar duración/resultado, nunca destruir producción. Acreditar por separado recuperación de respaldo productivo y límites efectivamente observados. |
| RF-125 | **MAIN + PARCIAL** | Aislamiento tenant ya es invariante; el scope por entidad legal se aplica donde corresponda y requiere extensión consistente de permisos/consultas. |

## 5.23 Experiencia de usuario — RF-126 a RF-129

| RF | Cobertura | Traducción correcta a Condor |
| --- | --- | --- |
| RF-126 | **PRINCIPIO** | Simplicidad por defecto y complejidad progresiva ya son decisiones de producto. |
| RF-127 | **PRINCIPIO** | Flujos frecuentes deben optimizarse por tarea completa; evitar asistentes innecesarios y confirmaciones redundantes. |
| RF-128 | **NUEVO** | Capa reusable de búsqueda/lookup por identificadores y texto; barcode es una entrada más, no un buscador separado. |
| RF-129 | **PRINCIPIO / PARCIAL** | Admin React debe ser responsive/mobile-first; ciertas operaciones densas pueden optimizarse para desktop sin quedar inutilizables en tablet/móvil. |

---

# 6. Agrupación de brechas en capacidades de producto

Los 129 RF no equivalen a 129 features independientes. Se condensan en capacidades coherentes:

| Capacidad Condor | RF principales | Tratamiento |
| --- | --- | --- |
| Entidades legales y contexto multiempresa | 001–004, 111, 125 | **Núcleo** |
| Inventory completo | 005–024, 047, 049, 077 | **Núcleo comercial/operativo**; #171/#172 ya lo inicia |
| Unidades de medida + materiales/componentes | 015–024 | **Núcleo operativo reusable** |
| Manufacturing + BOM + trazabilidad | 025–034 | **Módulo opcional** |
| Costing | 035–043, 054 | **Módulo opcional**, integrable con Manufacturing/Inventory/Pricing |
| Barcode/labels/scan | 044–047, 128 | **Capacidad transversal + adaptadores de impresión** |
| Channels + Orders/Sales | 048–059, 074–078 | **Núcleo comercial** |
| Storefront B2C/B2B + Quotes | 055–063 | **Módulo comercial configurable** |
| Customer + Pricing | 064–069, 079–082 | **Núcleo comercial** |
| Media/Assets | 070–073 | **Núcleo transversal** |
| Procurement | 083–088 | **Módulo opcional** |
| People/HR | 089–093 | **Módulo opcional** |
| Time & Attendance | 094–098 | **Módulo opcional + adaptadores biométricos** |
| Identity/Permissions | 099–104 | **Núcleo**, ampliamente implementado |
| Audit | 105–108 | **Núcleo parcial**: entidades disponibles; preservar historial ante borrado de tenant y completar vistas de auditoría |
| Analytics/Dashboard | 109–114, 118–121 | **Capacidad transversal** sobre read models |
| Alerts/Notifications | 115–117 | **Motor transversal**, Notification como base |
| Backup/Security | 122–125 | **Plataforma parcial**: script/ensayo CI integrados; periodicidad, copia externa y recuperación productiva pendientes |
| UX/Search/Responsive | 126–129 | **Principios + capacidades transversales** |

Esto evita la lectura equivocada de “hay que construir 129 cosas”. En realidad el cliente revela aproximadamente **18 capacidades**, varias ya definidas o iniciadas.

---

# 7. Reglas de diseño que quedan validadas

## Decisiones del primer cliente pendientes de validación

Esta matriz contrasta necesidades con arquitectura; **no sustituye la confirmación del cliente ni acredita capacidades productivas que todavía no están operando**. Antes de usar los RF como alcance de un slice se deben dejar respuestas verificables en el Issue del slice correspondiente:

| Contrato pendiente | Decisión que se debe registrar | Evidencia exigida |
| --- | --- | --- |
| RF-001 / RF-004, asignación concreta | #177 resuelve **arquitectura**, no datos de onboarding: registrar pertenencia de cada razón social al grupo, usuarios, sedes, fuentes, roles y selector (oculto con entidad única). | Matriz de usuarios × razón social × sede y pruebas HTTP de acceso permitido/denegado; ningún permiso surge automáticamente por cambiar contexto. |
| RF-002, propiedad de datos | Identificar dueño jurídico de existencias, producto/variante, venta, costo y reporte; diferenciar el alcance tenant/sede del alcance entidad legal. | Relaciones y consultas explícitas; lectura/escritura cross-entity denegada; reportes y exportaciones filtrados por autorización. |
| RF-003, operación interempresa | Precisar si se trata de servicio de fabricación, compra/venta entre sociedades, traslado físico por cuenta ajena u otra operación. | Contrato origen/destino con titularidad, precio/valuación, documento, evento auditable y flujo de reversión. El movimiento físico de stock no se reutiliza como asiento comercial. |
| RF-122 / RF-124, continuidad | Implementar baseline D-040: DB diaria, archivos/media cubiertos, 30 días si hosting lo permite, copia externa con datos reales, RPO ≤24 h, RTO ≤8 h y ensayo trimestral aislado; registrar responsables/destino/acceso. | Pruebas de cron y estado de respaldos productivos **sin publicar datos**; bitácora de ensayo de restauración real autorizado, métricas de recuperación y alertas, distinta de la prueba CI. |

El draft #171/#172 ya protege **tenant + fuente + variante**, pero #177 exige además entidad legal efectiva por fuente y prohibir transferencias simples entre razones sociales antes del merge. No atribuirle todavía separación jurídica, autorización cross-entity ni intercompany mientras el modelo, API y pruebas no lo demuestren; la adaptación de la rama debe ser explícita y serial.

## R1 — Alcance explícito en vez de duplicación

Toda entidad operacional deberá declarar de forma explícita qué scope posee:

- tenant;
- entidad legal cuando aplique;
- sede cuando aplique;
- canal cuando aplique;
- fuente de inventario cuando aplique.

No se deduce el scope por nombres ni se duplica el mismo modelo por empresa.

## R2 — Un solo dueño de cada invariante

Ejemplos:

- Inventory es el único dueño de saldos/movimientos.
- Pricing es el único dueño del precio efectivo.
- Orders es el dueño del ciclo comercial del pedido.
- Manufacturing es dueño del proceso productivo, pero consume Inventory.
- Costing calcula/almacena costos, pero no altera movimientos de inventario.
- Audit registra trazabilidad, pero no reemplaza el historial propio de dominio.

## R3 — Integración mediante contratos/eventos

Los módulos se relacionan por IDs estables, casos de uso y eventos. No por escrituras directas sobre tablas ajenas.

Ejemplos futuros:

- `PurchaseReceiptConfirmed` → Inventory registra entrada.
- `ProductionMaterialConsumed` → Inventory registra salida.
- `ProductionCompleted` → Inventory registra output.
- `OrderConfirmed` → Inventory reserva/consume según política.
- `InventoryBelowThreshold` → Alerts crea señal.
- `CostSnapshotCalculated` → Analytics actualiza métricas derivadas.

## R4 — Industria como plantilla, no fork

Una empresa de confección puede arrancar con:

- talla/color;
- metros/rollos;
- tela/cierre/hilo;
- corte/confección/empaque;
- motivos de merma;
- KPIs textiles.

Una ferretería, restaurante o distribuidor usarán otras configuraciones sobre los mismos motores donde el concepto sea común.

## R5 — Capability flags y navegación por módulo

Cada tenant activa únicamente las capacidades contratadas/necesarias. El shell administrativo, permisos, navegación, onboarding y dashboard deben derivar de esas capacidades.

## R6 — Flexibilidad limitada por invariantes

“Muy configurable” no significa que todo sea editable.

Permanecen fijos:

- aislamiento tenant;
- autorización server-side;
- integridad referencial;
- auditoría de cambios sensibles;
- idempotencia donde duplicar efectos sea peligroso;
- transacciones/locking donde se mueva stock/dinero;
- un precio efectivo determinista;
- estados semánticos internos compatibles;
- secretos fuera del repositorio.

Lo configurable vive encima de esas garantías.

---

# 8. Qué debe ocurrir cuando llegue el segundo cliente

No se compara el segundo cliente directamente con Marcela Arias/Fabritex. Se compara contra el **catálogo canónico de capacidades de Condor**.

Proceso:

1. Registrar requerimientos originales sin reinterpretarlos.
2. Mapear cada requerimiento a una capacidad Condor.
3. Marcar: cubierto, extensión reusable, configuración, módulo, adaptador o no soportado.
4. Detectar conceptos nuevos.
5. Generalizar el concepto antes de modelar tablas/API/UI.
6. Añadir configuración/plantilla vertical cuando la diferencia sea sectorial.
7. Promover al núcleo únicamente invariantes verdaderamente transversales.
8. Crear Issues de implementación por **slice/capacidad**, no por cliente ni por RF individual.
9. Mantener una matriz de trazabilidad Cliente RF → Capacidad → Issue/PR/Test/Versión.

Con cada nuevo cliente, la arquitectura debería requerir **menos excepciones**, no más.

---

# 9. Dependencias entre capacidades

Esta matriz identifica **dependencias funcionales**, no la secuencia de implementación, el sprint ni una promesa de releases. La prioridad, asignación y el orden de ejecución pertenecen exclusivamente al [Roadmap canónico, Issue #1](https://github.com/pl0n3r/Condor/issues/1).

| Capacidad | Requiere para funcionar correctamente |
| --- | --- |
| Inventory multi-entidad | Titularidad jurídica y controles de #177, fuente, variante, movimientos e idempotencia; la transferencia interna no crea una operación intercompany. |
| Orders/Sales y canales | Customer, Pricing y stock transaccional con alcance por entidad legal. |
| Storefront y checkout | Catálogo/variantes, canales, disponibilidad efectiva y Orders; ambas marcas pueden tener experiencias públicas diferenciadas sobre motor compartido. |
| Procurement y Manufacturing | Materiales/unidades, Inventory trazable y contratos de transacciones entre entidades cuando aplique. |
| Costing y Analytics | Eventos/instantáneas históricos de producción, inventario y ventas; filtros por entidad y permisos sensibles. |
| Alerts y búsquedas | Fuentes de eventos y datos confiables, autorización y reglas configurables. |
| People/HR y Attendance | Identidad separada de empleado, permisos de datos personales e integraciones de dispositivo adaptables. |

La dependencia no fija una prioridad comercial. Cada slice se abre y serializa desde el Roadmap #1 y su Issue, evitando una segunda cola de ejecución.

---

# 10. Conclusión de arquitectura

Los requerimientos de Marcela Arias / Fabritex **no contradicen la dirección de Condor**. La mayoría confirma decisiones previas y revela módulos naturales que todavía no estaban priorizados.

La adaptación correcta no es “hacer Condor para una fábrica de ropa”, sino evolucionar Condor hacia:

> **una plataforma modular de operación empresarial, simple por defecto, donde una organización activa capacidades comerciales, inventario, abastecimiento, manufactura, costos, personas, analítica e integraciones según su realidad, manteniendo un único núcleo coherente y trazable.**

El cliente textil se vuelve una primera validación vertical de módulos genéricos. El próximo cliente deberá poder reutilizar esos mismos módulos sin heredar vocabulario, reglas ni pantallas exclusivas de confección.
