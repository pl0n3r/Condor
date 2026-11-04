# Guía de módulos operativos por tipo de negocio

> **Corte de código:** `main@b6ee2c18e714872538bfde4c9b97cdb1db760764` (4 de noviembre de 2026). Fuente: controladores, servicios y pruebas versionados; no se infiere despliegue ni disponibilidad comercial. **Estado general: construcción/pre-live.**

Esta guía ayuda a escoger por dónde empezar. **«Presente en main»** significa que hay implementación verificable en código; **no** significa que la pantalla esté terminada, que el flujo haya sido validado en producción o que se pueda ofrecer hoy a clientes reales. La columna **«en construcción»** recoge únicamente lo todavía pendiente del alcance descrito; una función presente en el checkout puede seguir sin estar desplegada, habilitada o validada para usuarios reales. Las decisiones de planes, facturación, go-live y tratamiento de datos reales requieren sus propias puertas.

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
**Presente en main:** inventario por sede, ajustes y consulta de existencias; alertas de stock bajo y mínimos configurables, transferencias manuales entre sedes autorizadas, escaneo de códigos y ajuste CSV (vista previa y aplicación con permisos). Las pantallas están conectadas en `frontend/admin/AdminApp.tsx`. Referencias de API y pantalla: `src/Http/Controller/InventoryController.php` + `frontend/admin/InventoryManagement.tsx`; `src/Http/Controller/StockAlertController.php` + `frontend/admin/StockAlertsPanel.tsx`; `src/Http/Controller/StockTransferController.php` + `frontend/admin/StockTransferPanel.tsx`; `src/Http/Controller/ScanController.php` + `frontend/admin/InventoryManagement.tsx`; `src/Http/Controller/InventoryCsvAdjustController.php` + `frontend/admin/StockCsvAdjust.tsx`.
**En construcción:** transferencias automáticas intersede y validación de notificaciones y flujos con operación real; los controles visibles en código no prueban funcionamiento o despliegue productivo.
**Empezar por:** registrar sedes, identificar variantes y hacer un ajuste controlado con datos de prueba.

### 5. Clientes y categorías
**Presente en main:** API tenant-scoped para clientes y categorías; la identidad de cliente comercial es distinta de la de usuario. Referencia: `src/Http/Controller/CustomerController.php`.
**En construcción:** CRM avanzado, automatizaciones multicanal e integraciones externas son posibilidades, no capacidades listas.
**Empezar por:** establecer categorías, campos obligatorios y permisos de acceso por sede.

### 6. Compras y proveedores
**Presente en main:** API de proveedores, órdenes de compra y recepción por tenant/sede; pantalla de compras y cálculo/consulta de costo promedio histórico de materiales recibidos, con estado `KNOWN` o `UNKNOWN` cuando falta evidencia. Referencias de controlador y pantalla: `src/Http/Controller/PurchasingController.php` + `frontend/admin/PurchasingManagement.tsx`; cableado en `frontend/admin/AdminApp.tsx`.
**En construcción:** conciliación contable, facturación, conexión comercial con proveedores y comprobación del flujo con datos reales; el costo promedio en código no acredita una valoración contable aprobada.
**Empezar por:** simular proveedor, orden y recepción; contrastar impacto de inventario antes de operar.

### 7. Producción y materiales
**Presente en main:** API de materiales (BOM), órdenes, cierre y consulta del costo por orden; la pantalla administrativa presenta costo total, unitario y consumos cuando hay un comprobante persistido válido. La salida es **costo estimado** (`ESTIMATED_COST`), no costo contable definitivo; si no hay respaldo permanece desconocida. Referencias de controladores y pantalla: `src/Http/Controller/ProductionOperationsController.php` + `frontend/admin/ProductionManagement.tsx`; `src/Http/Controller/ProductionCostController.php` + `frontend/admin/ProductionManagement.tsx`; cableado en `frontend/admin/AdminApp.tsx`.
**En construcción:** valoración contable definitiva, facturación/conciliación y operación productiva acreditada. No incluye nómina ni contabilidad.
**Empezar por:** modelar producto, materiales y orden de prueba; revisar consumos y permisos.

### 8. Reportes CSV
**Presente en main:** servicio, controlador y pantalla de descarga de reportes CSV de inventario, ventas y compras, con filtros de fecha, contexto de sede y permisos; botón visible en `frontend/admin/ReportsDownloads.tsx`, conectado desde `frontend/admin/AdminApp.tsx`. Referencias de controlador y pantalla: `src/Http/Controller/ReportsController.php` + `frontend/admin/ReportsDownloads.tsx`; servicio `src/Application/Reporting/TenantCsvReportService.php` y pruebas `tests/test_reports_csv.py`.
**En construcción:** validación de los datos y descargas reales en el entorno de producción; no hay garantía de acceso comercial ni de reportes contables certificados.
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
4. Comprobar el costo **estimado** total/unitario por orden terminada si existe comprobante válido; siguen **en construcción** la valoración contable y la conciliación, sin prometer un ERP contable.

### Recorrido C — Distribuidora con sedes
1. Definir sedes y permisos; usar **Inventario y sedes** con fuentes por sede.
2. Probar **Catálogo y precios**, **Clientes y categorías** y **Pedidos y ventas**.
3. Añadir pruebas de **Compras y proveedores** y contrastar **Reportes CSV** por tenant.
4. Contrastar las alertas de stock, transferencias **manuales**, escaneo y ajustes CSV que ya constan **presentes en main**; las transferencias **automáticas** y la validación productiva siguen **en construcción**.

### Recorrido D — Grupo de empresas
1. Modelar cada empresa como tenant separado; no consolidar información saltando permisos.
2. Validar roles y acceso al contexto de cada sede; los módulos **Clientes y categorías**, **Inventario y sedes** y **Reportes CSV** deben preservar aislamiento.
3. Mantener el control de propietarios/planes separado de la operación diaria.
4. Consolidación interempresas, facturación conjunta y operación real están **en construcción**: no ofrecer visibilidad cruzada sin una autorización explícita.

## Límite de esta guía

No hay garantía de que los ocho módulos estén desplegados, habilitados para todos los planes, aprobados para clientes reales ni completos de extremo a extremo. **No activar live**, facturación, datos reales, proveedores externos ni cambios de dominio desde estas recomendaciones. Cada módulo pasa de «presente en main» a «operativo» solo mediante sus pruebas, permisos, observación de despliegue y validación productiva independientes. Consultar `AGENTES.md`, `ESPECIFICACIONES.md`, el roadmap en el issue #1 y `docs/api-rest-contracts.md` para contratos vigentes.
