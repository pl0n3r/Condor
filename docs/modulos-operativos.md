# Guía de módulos operativos por tipo de negocio

> **Corte de código:** `main@6538d9b31ea6d91d25f62f5b7128bc2efb8f0143` (20 de octubre de 2026). Fuente: controladores, servicios y pruebas versionados; no se infiere despliegue ni disponibilidad comercial. **Estado general: construcción/pre-live.**

Esta guía ayuda a escoger por dónde empezar. **«Presente en main»** significa que hay implementación verificable en código; **no** significa que la pantalla esté terminada, que el flujo haya sido validado en producción o que se pueda ofrecer hoy a clientes reales. Cuando el API, la interfaz o la integración final están pendientes, se señala **«en construcción»**. Las decisiones de planes, facturación, go-live y tratamiento de datos reales requieren sus propias puertas.

## Ocho módulos y estado verificable

### 1. Catálogo y precios
**Presente en main:** API de productos y variantes por sede, y lógica de precios comerciales. Referencias: `src/Http/Controller/CatalogController.php`, `src/Http/Controller/PricingController.php`, `frontend/admin/CatalogManagement.tsx`.
**En construcción:** confirmar cada política comercial/mayorista y su interfaz con los criterios del negocio antes de venderla como función definitiva.
**Empezar por:** crear un catálogo pequeño y revisar variantes, unidades y reglas de precios con datos sintéticos.

### 2. Tienda y checkout
**Presente en main:** controladores de superficie pública y endpoint de checkout; no equivale a integración de pagos. Referencias: `src/Http/Controller/StorefrontCheckoutController.php`, `src/Http/Controller/StorefrontAdminController.php`, `frontend/admin/CommerceManagement.tsx`.
**En construcción:** operación comercial integral, pasarelas de pago, envíos y dominio propio no están acreditados aquí como despliegue final.
**Empezar por:** configurar escaparate y simular un pedido antes de habilitar ventas reales.

### 3. Pedidos y ventas
**Presente en main:** rutas para listar, crear, cancelar y consumir pedidos según permisos/contexto de sede; pantalla administrativa de pedidos. Referencias: `src/Http/Controller/OrderController.php`, `frontend/admin/OrdersManagement.tsx`.
**En construcción:** el flujo completo de recepción, facturación y conciliación operativa depende de gates independientes.
**Empezar por:** definir estados de pedido y probar cambios de stock en entorno de prueba.

### 4. Inventario y sedes
**Presente en main:** fuentes de existencias, ajustes y snapshot por sede, con interfaz administrativa de inventario. Referencias: `src/Http/Controller/InventoryController.php`, `frontend/admin/InventoryManagement.tsx`.
**En construcción:** alertas automáticas de stock bajo y escaneo de códigos en el admin siguen en hojas separadas; no afirmar que están integradas.
**Empezar por:** registrar sedes, identificar variantes y hacer un ajuste controlado con datos de prueba.

### 5. Clientes y categorías
**Presente en main:** API tenant-scoped para clientes y categorías; la identidad de cliente comercial es distinta de la de usuario. Referencia: `src/Http/Controller/CustomerController.php`.
**En construcción:** CRM avanzado, automatizaciones multicanal e integraciones externas son posibilidades, no capacidades listas.
**Empezar por:** establecer categorías, campos obligatorios y permisos de acceso por sede.

### 6. Compras y proveedores
**Presente en main:** API de proveedores, órdenes de compra y recepción, con permisos de tenant/sede. Referencia: `src/Http/Controller/PurchasingController.php`.
**En construcción:** la pantalla integral de compras y el costo histórico ponderado dependen de PRs todavía abiertos; no tratarlos como entregados.
**Empezar por:** simular proveedor, orden y recepción; contrastar impacto de inventario antes de operar.

### 7. Producción y materiales
**Presente en main:** API de listas de materiales (BOM), órdenes y cierre de producción; existe una pantalla base administrativa. Referencias: `src/Http/Controller/ProductionOperationsController.php`, `frontend/admin/ProductionManagement.tsx`.
**En construcción:** costo total/unitario por orden y su desglose en la UI dependen de trabajos pendientes. No incluye nómina ni contabilidad.
**Empezar por:** modelar producto, materiales y orden de prueba; revisar consumos y permisos.

### 8. Reportes CSV
**Presente en main:** servicio y pruebas de CSV aislados por tenant para inventario, ventas y compras. Referencias: `src/Application/Reporting/TenantCsvReportService.php`, `tests/test_reports_csv.py`.
**En construcción:** la pantalla de descarga con filtros de fecha dentro del administrador está planificada aparte; un servicio probado no equivale a botón visible.
**Empezar por:** validar columnas, rango y aislamiento entre tenants sobre información ficticia.

## Recorridos sugeridos (preparación, no producción)

### Recorrido A — Tienda
1. Iniciar por **Catálogo y precios** con un puñado de variantes.
2. Probar **Inventario y sedes**, **Tienda y checkout** y **Pedidos y ventas** en ese orden.
3. Comprobar que el precio y el stock son recalculados/validados por el servidor; la aceptación de pagos y la salida a producción siguen **en construcción**.
4. Usar **Clientes y categorías** solo cuando el flujo comercial lo requiera.

### Recorrido B — Taller
1. Configurar **Catálogo y precios** e **Inventario y sedes** para los materiales.
2. Registrar BOM y órdenes de prueba en **Producción y materiales**.
3. Evaluar **Compras y proveedores** para la reposición.
4. El costo por orden integrado en pantalla sigue **en construcción**, y no se debe prometer un ERP contable.

### Recorrido C — Distribuidora con sedes
1. Definir sedes y permisos; usar **Inventario y sedes** con fuentes por sede.
2. Probar **Catálogo y precios**, **Clientes y categorías** y **Pedidos y ventas**.
3. Añadir pruebas de **Compras y proveedores** y contrastar **Reportes CSV** por tenant.
4. Transferencias automáticas intersede, alertas y escaneo UI están **en construcción** hasta que sus hojas estén integradas.

### Recorrido D — Grupo de empresas
1. Modelar cada empresa como tenant separado; no consolidar información saltando permisos.
2. Validar roles y acceso al contexto de cada sede; los módulos **Clientes y categorías**, **Inventario y sedes** y **Reportes CSV** deben preservar aislamiento.
3. Mantener el control de propietarios/planes separado de la operación diaria.
4. Consolidación interempresas, facturación conjunta y operación real están **en construcción**: no ofrecer visibilidad cruzada sin una autorización explícita.

## Límite de esta guía

No hay garantía de que los ocho módulos estén desplegados, habilitados para todos los planes, aprobados para clientes reales ni completos de extremo a extremo. **No activar live**, facturación, datos reales, proveedores externos ni cambios de dominio desde estas recomendaciones. Cada módulo pasa de «presente en main» a «operativo» solo mediante sus pruebas, permisos, observación de despliegue y validación productiva independientes. Consultar `AGENTES.md`, `ESPECIFICACIONES.md`, el roadmap en el issue #1 y `docs/api-rest-contracts.md` para contratos vigentes.
