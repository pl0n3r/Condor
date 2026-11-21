# Políticas comerciales y mayoristas de precios: propuesta para decisión del dueño

**Issue:** [Condor #1514](https://github.com/pl0n3r/Condor/issues/1514) · **Estado:** propuesta pendiente de aprobación comercial explícita del OWNER en el PR · **Fase:** `construccion` · **Moneda de referencia:** COP.
**Autorización existente:** [comentario del dueño en #1514](https://github.com/pl0n3r/Condor/issues/1514#issuecomment-7053851984) permite **redactar** este documento, no declara aprobadas las políticas de la tabla. Las recomendaciones son opciones para revisar, no precios, reglas activadas ni instrucciones de go-live.

## 1. Problema, público y criterio de éxito

La empresa usuaria de Condor necesita administrar precios por variante, canal y segmento de cliente sin calcular manualmente cada venta ni revelar condiciones mayoristas a quien no corresponda. La interfaz debe funcionar para una empresa con una sola lista y crecer a varios canales/categorías sin obligar al usuario a configurar reglas avanzadas.

**Criterio observable propuesto:** en un tenant sintético, un administrador autorizado puede explicar **un único** precio de una variante para un canal/cliente/fecha/cantidad; el sistema identifica lista, regla, vigencia y motivo de selección. Si faltan datos o permisos, falla cerrado. Para el MVP, las políticas adicionales elegidas por el dueño deben tener escenarios concretos y regresiones reproducibles antes de anunciarse como disponibles. Esto no es una medición de uso real.

**Frontera:** precios de productos/servicios vendidos **por un tenant**. **No** se definen ni modifican tarifas de suscripción de Condor, planes, add-ons o verticales del catálogo de plataforma: ver [#1448](https://github.com/pl0n3r/Condor/issues/1448), [#1682](https://github.com/pl0n3r/Condor/issues/1682) y [docs/catalogo-planes.md](catalogo-planes.md). Tampoco se cobra, factura, conecta proveedor fiscal ni se procesan datos reales.

## 2. Qué existe en el código (no equivale a oferta comercial aprobada)

| Fuente vigente en `main` | Alcance observado | Límite actual |
| --- | --- | --- |
| [PricingController](../src/Http/Controller/PricingController.php) | API de listas, precios de variantes, asignación de lista preferida a categoría, reglas y lectura de precio efectivo; permisos `pricing.view/create/update/delete`, CSRF en mutaciones | No define por sí solo qué productos comerciales deben ofrecerse |
| [CommerceManagement](../frontend/admin/CommerceManagement.tsx) | React de clientes/categorías, listas, precio por variante, estados de carga/error y controles por permiso | No es editor general de tramos de cantidad ni de contratos de mayorista |
| [PricingService](../src/Application/Commerce/PricingService.php) | Resuelve lista solicitada o preferida de categoría de cliente, verificando tenant, lista activa y variante | Requiere contexto de lista válido; no introduce automáticamente precio para cliente sin lista |
| [PriceRule](../src/Domain/Commerce/Entity/PriceRule.php) y [EffectivePriceResolver](../src/Domain/Commerce/EffectivePriceResolver.php) | Descuento fijo o porcentual, prioridad, especificidad de categoría, vigencia; **gana una sola regla** por prioridad descendente, especificidad descendente, ID ascendente | Las reglas no tienen predicado nativo de cantidad; descuentos **no se apilan** en el resolver existente |
| [EffectivePriceExplainer](../src/Application/Commerce/EffectivePriceExplainer.php) y [EffectivePriceController](../src/Http/Controller/EffectivePriceController.php) | GET explicativo por variante/canal/cantidad/fecha, una lista configurada por canal y total calculado como cantidad × precio unitario | Declara `quantity_policy=unit_price_times_quantity_no_volume_rules`; **cantidad no activa descuentos por volumen**. La explicación en canal pasa cliente `null`, por lo que una regla exclusiva de categoría no participa en ese flujo |
| [ESPECIFICACIONES.md](../ESPECIFICACIONES.md) D-019, D-027, D-028 y D-029 | Defaults: cambio manual auditable, categorías por empresa, lista por canal, un solo precio efectivo y precedencia determinista | Son principios funcionales y decisiones previas, **no** aprobación de las políticas pendientes de #1514 |

**Integración y seguridad:** tenant/branch se autorizan en servidor; una lista, variante, cliente o categoría ajenos no deben ser visibles ni editables. El frontend es solo presentación, nunca fuente de verdad del importe ni del permiso. El precio de un pedido confirmado se congela como snapshot según D-032. La disponibilidad proviene de la fuente de inventario del canal y no del precio.

## 3. Matriz comercial para que decida el dueño (AC-01 pendiente)

**Todas las celdas «Recomendación» son propuestas**. El OWNER debe convertir cada fila en `ofrecer`, `posponer` o `descartar` mediante aprobación/corrección explícita de este PR; hasta entonces su estado es **pendiente**. No establecer descuentos, porcentajes, umbrales, márgenes ni tarifas sin voto.

| ID | Política a decidir | Estado técnico | Recomendación de producto, NO aprobada | Decisión OWNER |
| --- | --- | --- | --- | --- |
| P01 | Precio base por variante y lista por tenant | API + UI existentes | **Ofrecer** como fundamento del MVP, sin activar valores ficticios en clientes reales | Pendiente |
| P02 | Lista de precios seleccionada por canal | Dominio/flujo explicativo existente | **Ofrecer** con una lista predeterminada por canal y estados sin lista | Pendiente |
| P03 | Categorías comerciales de clientes, incluida «mayorista» definida por la empresa, con lista preferida | API + UI existentes | **Ofrecer** sin categoría global codificada y con relación opcional; validar contexto del cliente | Pendiente |
| P04 | Reglas temporales de descuento fijo/porcentaje según lista/categoría, solo una ganadora | Motor/API presentes; UI específica de reglas a contrastar | **Ofrecer** en editor avanzado solo después de validar su cobertura de UI y pruebas | Pendiente |
| P05 | Descuentos escalonados por cantidad/volumen (por variante o tramo) | **No soportados** en el resolver explicativo actual | **Posponer** hasta contrato de tramos, concurrencia y pruebas E2E | Pendiente |
| P06 | Precio manual negociado al crear una línea de pedido con motivo y auditoría | D-019 define configurabilidad y motivo por defecto; no acreditada UI completa aquí | **Posponer** hasta pruebas del recorrido y permisos | Pendiente |
| P07 | Descuentos combinados/apilados (por producto + categoría + volumen + cupón) | Incompatible con política actual de una sola regla ganadora | **Descartar del MVP**, reconsiderar solo con caso real y decisión de precedencia | Pendiente |
| P08 | Condiciones de crédito, mínimos obligatorios de compra, exclusividad contractual mayorista | No verificado en política de precios vigente | **Posponer**; separar condiciones de venta/contrato del cálculo del precio | Pendiente |
| P09 | Cambio retroactivo de precios de pedidos confirmados | Contradice D-032 | **Descartar**: preservar snapshots históricos | Pendiente (restricción normativa vigente) |
| P10 | Precios multi-moneda/cambio automático de divisa | Lista admite moneda; no hay conversor de divisas aprobado | **Posponer** la conversión dinámica; mostrar importes solo en la moneda de la lista | Pendiente |

### Decisiones concretas que debe dejar la revisión del PR

- **Oferta inicial:** ¿aprobar P01–P03 como funciones visibles y P04 como avanzado, o reducir la primera entrega? No asumir que una funcionalidad con código existe en producción.
- **Mayoristas:** ¿basta con categoría y lista propia (recomendado) o se necesitan condiciones negociadas/volumen? Si se necesita volumen, decidir unidades medibles y umbrales en otro contrato, no inventarlos.
- **Precedencia:** conservar por defecto una sola regla ganadora y un precio efectivo por variante/contexto. Toda combinación nueva necesita regla de desempate explícita, contrato y regresión antes de activarse.
- **Permisos:** confirmar qué roles de cada empresa podrán consultar, editar reglas y autorizar cambios manuales. No inferir permisos nuevos de los CRUD genéricos.

**Default seguro hasta la aprobación:** no exponer políticas nuevas, no crear ni modificar descuentos, no anunciar mayoristas ni activar automatizaciones que cambien precios.

## 4. Especificación de interfaz propuesta (AC-02: diseño documental)

**Ruta conceptual:** `/admin#commerce` con secciones de **Listas de precios**, **Categorías comerciales**, **Precios por variante** y, solo si se aprueba P04, **Reglas avanzadas**. Reutilizar componentes y permisos del módulo actual `CommerceManagement`; no diseñar una app paralela. La ruta precisa, los hooks y las acciones se validarán en la hoja ejecutable de UI.

### Lista/precio base (P01, P02)

- Cabecera «Precios comerciales» con selector de sede y canal disponible, siempre dentro del tenant autorizado. Resumen de lista predeterminada y moneda; enlace a variantes.
- Tabla accesible: variante/SKU, lista, precio en COP (ejemplo solo visual, sin montos persistidos), vigencia/estado y acción «Editar» condicionada por `pricing.update`. Cantidades monetarias del backend en unidades menores; no usar `float` para calcular.
- Edición: formulario etiquetado, moneda indicada, validación de importe entero no negativo y confirmación previa. Guardar por API real con CSRF; refrescar snapshot, mostrar notificación accesible y mantener foco. Ante carrera/versión desactualizada **no** sobreescribir silenciosamente.
- Sin lista/variante/precio: explicar qué falta y ofrecer crear lista solo a quien tenga `pricing.create`; a quien solo consulte, mostrar estado informativo.

### Categoría comercial y mayorista (P03)

- La empresa crea categorías propias; «mayorista» es ejemplo de **nombre editable**, no categoría fija. Un cliente tiene una categoría principal efectiva y puede heredar la lista preferida.
- Tabla por categoría con nombre, lista preferida, estado y acciones condicionadas por permiso. Cambios auditables y sujetos al tenant. Nunca mezclar una lista ajena con un cliente.
- En la vista de pedido/consulta, mostrar contexto aplicado «canal / lista / categoría» y un precio unitario calculado por servidor. Si no hay categoría o lista aplicable, no generar un descuento por defecto.

### Reglas avanzadas (P04, solo tras aprobación)

- Abrir en panel lateral con nombre, lista, categoría opcional, descuento fijo o porcentual, prioridad entera, vigencia UTC y vista previa. Explicar «se aplica la regla ganadora» y mostrar `rule_id`, precio base, precio efectivo y motivo de descartes desde endpoint existente o contrato ampliado.
- Ante regla inválida, vencida, sin categoría coincidente o conflicto: mostrar error específico; no guardar datos a medias ni mostrar un precio alternativo basado en cálculo del navegador.
- Antes de añadir una pantalla operativa, probar la UX contra el backend `PricingController`; **este documento no acredita que hoy exista ese editor de reglas completo**.

### Estados y accesibilidad, escritorio/móvil

| Estado | UX esperada | Comportamiento seguro |
| --- | --- | --- |
| Cargando | Texto de progreso anunciado en `aria-live` | No permitir guardar ni suponer último precio |
| Vacío/sin lista | Texto de configuración pendiente, CTA autorizado | Sin precio ficticio o cero implícito |
| Error de red/500 | Alerta y botón Reintentar que conserva contexto | Nunca confirmar edición sin respuesta del servidor |
| 403/sin permiso | Lectura limitada o mensaje «No autorizado» | No mostrar acciones habilitadas y backend rechaza igualmente |
| Conflicto de edición | Estado anterior/nuevo y acción de recargar | Nunca sobrescribir silenciosamente |
| Vigencia vencida / precio ausente | Explicación y detalle aplicable | Bloquear confirmación de precio no determinista |

Usar `label` vinculado a input, teclado/foco visible, regiones `role=alert` para error, tablas legibles, panel apilado en 390 px y grilla a 1440 px; no depender solo de color. **Objetivo de prueba futuro**, no aseveración de Lighthouse/Playwright ya ejecutado. Los diseños avanzados se ocultan por defecto para reducir complejidad.

## 5. Contrato de cálculo y ejemplos puramente sintéticos

**Precedencia que sí existe hoy:** lista explícita o preferida de categoría (según endpoint) → precio por variante → filtrar reglas activas compatibles con tenant/lista/categoría/fecha → ordenar `priority DESC, specificity DESC, id ASC` → tomar una única regla → precio efectivo no negativo. Un canal puede fijar lista; el endpoint explicativo de canal no usa cliente/categoría y cantidad multiplica el unitario sin descuento por tramos.

**Ejemplo de laboratorio, no política tarifaria:** variante `SKU-FICTICIO` con base **10.000 unidades menores COP**, categoría `segmento-demo`, una regla sintética de 10 % produce 9.000 unidades menores cuando corresponde. Para 3 unidades, el endpoint de cantidad existente calcula 3 × precio unitario; **no aplica** un descuento adicional por cantidad. Ninguno de esos valores se propone para ventas reales.

**Futuros tramos (P05 no aprobado):** requerir claves tenant/lista/variante/categoría/canal, cantidad mínima y máxima sin solape, vigencia UTC, prioridad, empate determinista, descuentos seguros y elección explícita entre regla ganadora única o combinación. Revalidar en checkout/pedido con servidor, congelar precio al confirmar e impedir acceso cross-tenant. No añadir ese esquema en este PR.

## 6. Trazabilidad y acciones ejecutables (AC-03 condicionado a OWNER)

| Política | Fuente/Issue existente para reutilizar | Paso tras aprobación del dueño |
| --- | --- | --- |
| P01/P03 | [#179: clientes, categorías y precio efectivo](https://github.com/pl0n3r/Condor/issues/179); `PricingController` + `CommerceManagement` | Verificar regresión de lista/categoría/variante; solo crear nueva hoja si falta una brecha demostrada |
| P02 | [#181: canal y catálogo público](https://github.com/pl0n3r/Condor/issues/181); `EffectivePriceExplainer` | Regresión de canal y precio público/stock; no duplicar resolutor |
| P04 | [#864: precio explicable y precedencia](https://github.com/pl0n3r/Condor/issues/864) | Contrastar editor avanzado con API, estados de error, permisos y pruebas del mismo cálculo |
| P05 | [#1514](https://github.com/pl0n3r/Condor/issues/1514) como decisión, D-029 y `EffectivePriceExplainer` como evidencia del gap | Si OWNER elige **ofrecer**, solicitar hoja técnica para reglas de volumen, cálculo y E2E con rutas disjuntas; **no existe aún un issue ejecutable aprobado** |
| P06 | D-019 + [#183: pedido e inventario](https://github.com/pl0n3r/Condor/issues/183) | Antes de implementar, acreditar permisos, motivo, actor/UTC y snapshot de pedido; nueva hoja solo si aplica |
| P07/P08/P10 | D-027–D-029 | No abrir trabajo en estado «descartar»/«posponer» |
| Límite de planes | [#1448](https://github.com/pl0n3r/Condor/issues/1448) + [#1682](https://github.com/pl0n3r/Condor/issues/1682) | Separado: no cambia tarifas del SaaS |

**Restricción de gobernanza:** Factory #1289 limita crear nuevos Issues a motivos acreditados. La autorización para redactar #1514 **no** autoriza en bloque crear tareas o decidir condiciones comerciales. Por eso la matriz propone primero reutilizar Issues existentes y deja cualquier nueva hoja ejecutable sujeta a la decisión del dueño y su alcance exacto.

### Matriz de aceptación verificable para una futura entrega autorizada

| Capacidad que el dueño elija ofrecer | Aserción requerida en backend de pruebas | Aserción de interfaz pendiente, no ejecutada en este PR |
| --- | --- | --- |
| Lista/variante (P01) | Mismo tenant, lista activa y moneda; precio no negativo, variante ajena = 404 | Crear/editar con permiso y CSRF, vacío y 403 sin edición |
| Canal (P02) | Lista efectiva de canal; sin fallback de stock ni precio inventado | Selector de canal, explicación y estado sin lista |
| Categoría/mayorista (P03) | Una categoría principal; precio desde lista preferida, identidad cross-tenant rechazada | Nombre editable, sin etiqueta mayorista fija, accesible 390/1440 |
| Regla temporal (P04) | Una regla ganadora por prioridad/especificidad/ID, fecha y límites monetarios válidos | Editor avanzado, vista previa autoritativa y error ante vigencia inválida |
| Volumen/manual (P05/P06, **solo si se aprueban**) | Rangos sin solape o motivo/actor auditables; snapshot de pedido inmutable | Formulario/preview nuevo con negativos y prueba E2E real |

En la regresión futura deben coincidir el importe de la API, el pedido y la representación en UI; jamás se toma el precio visible como autoridad. Estas son **obligaciones de futuras hojas** y no resultados de pruebas actuales.

## 7. Evidencia, pruebas exigibles y reversión

- **Entregado en este PR:** especificación documental, matriz de opciones, flujos UI y trazabilidad de contratos existentes. **No entregado:** implementación nueva, precios o descuentos de clientes, métricas de UX, pruebas E2E nuevas, API y producción.
- **Prueba de documentación:** revisión de correspondencia con D-019/D-027/D-029 y con `EffectivePriceExplainer::quantity_policy`; CI `Validar` de la rama documental. No llamar `VALIDATED_IN_PRODUCTION` al resultado.
- **Prueba futura si el dueño aprueba interfaz:** escenarios UI 390/1440, teclado, carga/vacío/red/403/concurrencia; API servidor con tenant A/B y CSRF; precio efectivo determinista, categoría/lista, regla temporal y pedido confirmado que conserva snapshot.
- **Riesgo:** confundir categorías y políticas comerciales propuestas con funciones definitivas o inventar un descuento mayorista. Mitigación: sin activación y estado «pendiente» hasta aprobación explícita.
- **Reversión:** revertir el PR retira solo este archivo; no toca base de datos, migraciones, productos, contratos, tarifas, facturas, backups ni canales reales.

## 8. Puerta humana para completar #1514

El OWNER debe aprobar o corregir este documento **en la revisión del PR**, tal como indicó en su comentario. Solo entonces cambiar la columna «Decisión OWNER» a la elección explícita, actualizar si procede los criterios ejecutables y confirmar la necesidad/autorización de nuevos Issues. **La orden de redactar NO es aprobación de P01–P10.**

Estados independientes: **documento propuesto** → **revisión/decisión OWNER pendiente** → **PR documentado con CI** → **merge solo tras aprobación** → **ninguna habilitación comercial o producción por efecto de este PR**.
