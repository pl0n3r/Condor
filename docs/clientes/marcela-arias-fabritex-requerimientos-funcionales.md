# Primer cliente — Marcela Arias / Fabritex

## Requerimientos funcionales del software

**Estado:** requerimientos recibidos del cliente y versionados como fuente de producto.  
**Cliente:** Marcela Arias / Fabritex.  
**Fecha de incorporación a Condor:** 2026-09-22.  
**Trazabilidad:** Issue #175.

> Esta especificación documenta las necesidades expresadas por el primer cliente real de Condor. No autoriza hardcodear nombres, reglas o flujos exclusivos del cliente dentro del núcleo del SaaS. Cada capacidad deberá implementarse de forma reusable, multi-tenant y configurable cuando corresponda, preservando el aislamiento entre empresas/razones sociales y la trazabilidad exigida por Condor.
>
> Los identificadores RF-001 a RF-129 son estables y deben conservarse para trazabilidad futura hacia Issues, PRs, pruebas, módulos y entregas.

---

## Objetivo general

Desarrollar un software integral que permita administrar y controlar las operaciones de las dos razones sociales: Marcela Arias y Fabritex, manteniendo información independiente para cada empresa, pero permitiendo integraciones entre ambas cuando el proceso lo requiera.

El sistema debe centralizar inventarios, producción, costos, ventas, clientes, proveedores, recursos humanos y estadísticas gerenciales, permitiendo a la administración tener control y trazabilidad de todo lo que ocurre dentro de la empresa.

---

## 1. Gestión de empresas

**RF-001.** El sistema deberá permitir manejar de manera independiente las empresas Marcela Arias y Fabritex.

**RF-002.** Cada empresa deberá tener sus propios inventarios, ventas, movimientos, costos, reportes y usuarios asociados.

**RF-003.** El sistema deberá permitir relacionar operaciones entre Fabritex y Marcela Arias, especialmente cuando Fabritex fabrique productos destinados a Marcela Arias.

**RF-004.** Los usuarios autorizados deberán poder cambiar fácilmente entre una empresa y otra dentro del sistema.

## 2. Inventario de Marcela Arias

**RF-005.** El sistema deberá permitir administrar el inventario de productos terminados de Marcela Arias.

**RF-006.** Cada producto deberá manejar información como referencia, descripción, categoría, talla, color, costo, precio de venta, precio mayorista, existencia y código de barras.

**RF-007.** El sistema deberá permitir conocer la cantidad disponible de cada referencia, talla y color.

**RF-008.** El inventario deberá actualizarse automáticamente cuando se realice una venta.

**RF-009.** El sistema deberá permitir identificar por qué canal se realizó cada venta:

- Tienda física.
- TikTok.
- Página web.
- Venta mayorista.
- Otros canales futuros.

**RF-010.** El sistema deberá permitir realizar ajustes de inventario únicamente a usuarios autorizados.

**RF-011.** Cada ajuste deberá registrar el usuario responsable, fecha, hora, cantidad, referencia y motivo del ajuste.

**RF-012.** El sistema deberá generar alertas cuando un producto llegue a niveles mínimos de inventario.

**RF-013.** El sistema deberá mostrar productos agotados, con bajo inventario y productos de baja o alta rotación.

**RF-014.** El sistema deberá permitir realizar conteos físicos de inventario y compararlos con el inventario registrado en el sistema.

## 3. Inventario de Fabritex

**RF-015.** Fabritex deberá manejar un inventario independiente de materias primas, insumos y productos terminados.

**RF-016.** El sistema deberá controlar materiales como telas, herrajes, cierres, elásticos, hilos, etiquetas, empaques y demás insumos utilizados en producción.

**RF-017.** Cada materia prima deberá tener una unidad de medida definida, por ejemplo metros, kilos, unidades, rollos u otra unidad correspondiente.

**RF-018.** El sistema deberá registrar entradas de materias primas provenientes de compras o devoluciones.

**RF-019.** El sistema deberá registrar las salidas de materias primas utilizadas en producción.

**RF-020.** El sistema deberá registrar pérdidas, daños, desperdicios y mermas.

**RF-021.** Cada movimiento deberá indicar quién lo realizó, cuándo se realizó y cuál fue el motivo.

**RF-022.** El sistema deberá permitir conocer en tiempo real cuánto inventario existe de cada materia prima.

**RF-023.** El sistema deberá generar alertas de inventario mínimo para materias primas e insumos.

**RF-024.** El sistema deberá permitir establecer niveles mínimos y máximos de inventario.

## 4. Producción

**RF-025.** El sistema deberá incluir un módulo independiente de producción.

**RF-026.** El sistema deberá permitir crear órdenes de producción.

**RF-027.** Cada orden de producción deberá indicar como mínimo:

- Referencia a fabricar.
- Cantidad programada.
- Cantidad producida.
- Fecha.
- Responsable.
- Materiales requeridos.
- Materiales utilizados.
- Mermas.
- Productos defectuosos.
- Estado de la orden.

**RF-028.** El sistema deberá descontar del inventario de materias primas los materiales utilizados en cada producción.

**RF-029.** Al finalizar una producción, el sistema deberá ingresar automáticamente las unidades terminadas al inventario correspondiente.

**RF-030.** El sistema deberá permitir conocer cuánto material debería utilizarse para fabricar determinada cantidad de prendas.

**RF-031.** El sistema deberá comparar el consumo teórico de materiales contra el consumo real.

**RF-032.** El sistema deberá mostrar diferencias o desviaciones de consumo.

**RF-033.** El sistema deberá permitir registrar prendas defectuosas, reprocesos, desperdicios y pérdidas.

**RF-034.** El sistema deberá permitir conocer la trazabilidad completa de una producción desde la materia prima hasta el producto terminado.

## 5. Costos de producción

**RF-035.** El módulo de costos deberá manejarse de manera independiente del módulo de inventarios, aunque ambos deberán estar relacionados.

**RF-036.** El sistema deberá permitir calcular el costo de producción por referencia.

**RF-037.** El costo de una prenda podrá incluir:

- Tela.
- Herrajes.
- Hilos.
- Mano de obra.
- Etiquetas.
- Empaque.
- Procesos externos.
- Otros costos asociados.

**RF-038.** El sistema deberá calcular automáticamente el costo unitario de fabricación.

**RF-039.** El sistema deberá conservar históricos de costos para conocer cómo ha cambiado el costo de producción de una referencia.

**RF-040.** El sistema deberá permitir definir precios de venta a partir del costo.

**RF-041.** El sistema deberá manejar diferentes precios, incluyendo:

- Costo.
- Precio mayorista.
- Precio al detal.
- Precio promocional.

**RF-042.** El sistema deberá calcular automáticamente el margen de utilidad y la utilidad estimada por producto.

**Clarificación temporal para RF-039, RF-042 y RF-054.** Los reportes históricos de margen y rentabilidad deberán ser reproducibles y no podrán cambiar retroactivamente solo porque el costo vigente de un producto cambie después de la venta. El modelo deberá conservar una base histórica suficiente —por ejemplo un snapshot/referencia al costo aplicable a la transacción o a su fecha— y declarar el método de costeo utilizado. Si en el futuro se admiten distintos métodos de costeo por empresa, cada reporte deberá indicar cuál aplica y mantener resultados deterministas y auditables.

**RF-043.** La información relacionada con costos y utilidades deberá ser visible únicamente para usuarios autorizados.

## 6. Códigos de barras

**RF-044.** El sistema deberá generar códigos de barras para los productos de Marcela Arias y Fabritex.

**RF-045.** Los códigos deberán poder asociarse a referencias, tallas y colores.

**RF-046.** El sistema deberá permitir imprimir etiquetas con códigos de barras.

**RF-047.** El código de barras deberá poder utilizarse para ventas, inventarios, conteos, entradas, salidas y consultas de producto.

## 7. Ventas

**RF-048.** El sistema deberá centralizar las ventas realizadas por los diferentes canales.

**RF-049.** Toda venta deberá descontar automáticamente el inventario correspondiente.

**RF-050.** El sistema deberá registrar el canal de origen de cada venta.

**RF-051.** El sistema deberá permitir diferenciar ventas al detal y ventas al por mayor.

**RF-052.** El sistema deberá permitir consultar ventas por:

- Día.
- Semana.
- Mes.
- Año.
- Canal.
- Producto.
- Categoría.
- Cliente.
- Vendedor.

**RF-053.** El sistema deberá permitir consultar productos más vendidos y menos vendidos.

**RF-054.** El sistema deberá permitir analizar la rentabilidad de las ventas.

## 8. Página web Marcela Arias

**RF-055.** Marcela Arias deberá contar con una página web conectada directamente con el software.

**RF-056.** Los productos publicados en la página deberán estar sincronizados con el inventario.

**RF-057.** Cuando un cliente compre por la página web, el inventario deberá descontarse automáticamente.

**RF-058.** El sistema deberá evitar la venta de cantidades superiores a las existencias disponibles.

**RF-059.** Los pedidos realizados por la página deberán ingresar automáticamente al sistema.

## 9. Página web Fabritex

**RF-060.** Fabritex deberá contar con una página web orientada principalmente a clientes mayoristas, empresas, colegios y clientes que requieran producción o dotaciones.

**RF-061.** La página deberá permitir mostrar los productos y servicios ofrecidos por Fabritex.

**RF-062.** Los pedidos o solicitudes recibidos desde la página deberán quedar registrados dentro del sistema.

**RF-063.** El sistema deberá permitir posteriormente incorporar procesos de cotización para empresas y clientes institucionales.

## 10. Clientes mayoristas y detal

**RF-064.** El sistema deberá diferenciar clientes mayoristas y clientes al detal.

**RF-065.** Los clientes mayoristas deberán poder registrarse e iniciar sesión.

**RF-066.** Una vez autorizado como mayorista, el cliente deberá visualizar precios mayoristas.

**RF-067.** Los clientes al detal deberán visualizar precios al detal.

**RF-068.** El sistema deberá permitir que administración apruebe, rechace o cambie la categoría de un cliente.

**RF-069.** El sistema deberá conservar el historial de compras de cada cliente.

## 11. Banco de fotos y productos

**RF-070.** El sistema deberá contar con un banco centralizado de fotografías.

**RF-071.** Las imágenes deberán poder asociarse a referencias de producto.

**RF-072.** El sistema deberá permitir cargar varias fotografías por referencia.

**RF-073.** Las imágenes deberán poder utilizarse posteriormente para página web, catálogo, ventas mayoristas u otros canales.

## 12. Pedidos

**RF-074.** El sistema deberá permitir crear y consultar pedidos.

**RF-075.** Cada pedido deberá tener estados definidos, por ejemplo:

- Pendiente.
- Pagado.
- En preparación.
- Despachado.
- Entregado.
- Cancelado.

**RF-076.** El sistema deberá permitir identificar el cliente, vendedor, canal de venta y productos incluidos en cada pedido.

**RF-077.** Los pedidos deberán afectar el inventario según las reglas definidas por la empresa.

**RF-078.** El sistema deberá conservar el historial completo de cambios realizados sobre un pedido.

## 13. Clientes

**RF-079.** El sistema deberá incluir una base de datos centralizada de clientes.

**RF-080.** Cada cliente podrá tener información de contacto, historial de compras, tipo de cliente y observaciones.

**RF-081.** El sistema deberá permitir consultar cuánto ha comprado cada cliente.

**RF-082.** El sistema deberá identificar clientes frecuentes, clientes mayoristas y clientes inactivos.

## 14. Proveedores y compras

**RF-083.** El sistema deberá permitir registrar proveedores.

**RF-084.** Cada proveedor deberá tener un historial de compras.

**RF-085.** El sistema deberá permitir registrar compras de materias primas e insumos.

**RF-086.** Las compras deberán aumentar automáticamente el inventario correspondiente.

**RF-087.** El sistema deberá conservar el historial de precios pagados a cada proveedor.

**RF-088.** El sistema deberá permitir comparar el precio histórico de un mismo insumo.

## 15. Recursos humanos

**RF-089.** El sistema deberá incluir un módulo de recursos humanos.

**RF-090.** Deberá existir una ficha individual por empleado.

**RF-091.** La ficha podrá contener información laboral como cargo, área, fecha de ingreso, tipo de contrato, estado del empleado y documentos relacionados.

**RF-092.** El sistema deberá permitir registrar novedades relacionadas con el empleado.

**RF-093.** Los permisos de acceso al sistema deberán poder asignarse según el cargo o función del empleado.

## 16. Control de asistencia y huellero

**RF-094.** El sistema deberá permitir integrar, cuando técnicamente sea posible, la información proveniente del huellero biométrico utilizado por la empresa.

**Clarificación de privacidad para RF-094.** Por defecto, la integración deberá recibir únicamente los datos operativos mínimos necesarios para asistencia —por ejemplo identificador externo del empleado/dispositivo, tipo de marcación y fecha/hora— y **no deberá almacenar imágenes de huella, plantillas biométricas ni otros identificadores biométricos** en Condor. Si un proveedor o caso futuro exigiera procesar datos biométricos para que la integración funcione, esa capacidad deberá ser opcional y requerir antes de su implementación una definición explícita de propósito, minimización, base de acceso autorizado, protección/cifrado, retención, eliminación y auditoría. El adaptador del dispositivo deberá mantener esos datos separados del dominio general de asistencia siempre que sea técnicamente posible.

**RF-095.** El sistema deberá registrar hora de entrada y salida de los empleados.

**RF-096.** Deberá permitir identificar llegadas tarde, ausencias y novedades de asistencia.

**RF-097.** El sistema deberá generar reportes de asistencia por empleado y por periodo.

**RF-098.** Los responsables de administración o recursos humanos deberán poder revisar y justificar novedades cuando corresponda.

## 17. Usuarios, roles y permisos

**RF-099.** Cada empleado deberá ingresar al sistema utilizando un usuario individual.

**RF-100.** El sistema deberá manejar permisos según roles.

**RF-101.** Los permisos deberán poder definirse por módulo y por acción.

**RF-102.** Un empleado no deberá poder acceder a información financiera, de costos o gerencial si no tiene autorización.

**RF-103.** Gerencia y administración deberán tener perfiles con acceso ampliado.

**RF-104.** El sistema deberá permitir crear, modificar, bloquear y eliminar accesos de usuarios.

## 18. Auditoría y trazabilidad

**RF-105.** El sistema deberá mantener una bitácora de movimientos.

**RF-106.** La bitácora deberá registrar:

- Usuario.
- Acción realizada.
- Fecha.
- Hora.
- Información modificada.

**RF-107.** Los movimientos sensibles no deberán poder eliminarse sin dejar trazabilidad.

**RF-108.** Administración deberá poder consultar quién realizó modificaciones de inventario, producción, costos, pedidos y demás procesos relevantes.

## 19. Panel gerencial

**RF-109.** El sistema deberá contar con un dashboard principal para gerencia.

**RF-110.** El dashboard deberá presentar información de forma sencilla, gráfica e intuitiva.

**RF-111.** Gerencia deberá poder visualizar indicadores de Marcela Arias, Fabritex o ambas empresas.

**RF-112.** El panel deberá mostrar como mínimo:

- Ventas del día.
- Ventas del mes.
- Ventas por canal.
- Ventas de TikTok.
- Ventas de tienda física.
- Ventas de página web.
- Ventas mayoristas.
- Productos más vendidos.
- Productos menos vendidos.
- Inventarios bajos.
- Productos agotados.
- Rotación de inventario.
- Valor del inventario.
- Producción realizada.
- Producción pendiente.
- Consumo de materias primas.
- Mermas.
- Costos.
- Márgenes.
- Utilidades.
- Novedades relevantes de personal.

**RF-113.** El dashboard deberá utilizar alertas para señalar situaciones que requieran atención de gerencia.

**RF-114.** Los indicadores deberán poder filtrarse por fechas, empresa, canal, producto u otras variables relevantes.

## 20. Alertas

**RF-115.** El sistema deberá generar alertas automáticas ante situaciones importantes.

**RF-116.** Entre las alertas deberán contemplarse:

- Bajo inventario.
- Producto agotado.
- Materia prima insuficiente.
- Variación anormal de consumo.
- Merma elevada.
- Pedidos pendientes.
- Órdenes de producción retrasadas.
- Otros eventos configurables.

**RF-117.** Las alertas deberán visualizarse dentro del panel administrativo.

## 21. Reportes

**RF-118.** El sistema deberá generar reportes administrativos y gerenciales.

**RF-119.** Los reportes deberán poder filtrarse por fechas y diferentes criterios.

**RF-120.** El sistema deberá permitir exportar información a formatos como Excel y PDF.

**RF-121.** Los reportes deberán incluir información de inventario, ventas, producción, costos, clientes, compras, proveedores y recursos humanos.

## 22. Seguridad y respaldo

**RF-122.** El sistema deberá mantener respaldos periódicos de la información.

**RF-123.** Deberá contar con controles de acceso y seguridad para proteger información sensible.

**RF-124.** El sistema deberá permitir recuperar información ante una eventual pérdida de datos.

**RF-125.** La información de las dos empresas deberá mantenerse organizada y protegida según los permisos establecidos.

## 23. Experiencia de usuario

**RF-126.** El sistema deberá ser fácil e intuitivo para usuarios administrativos y operativos.

**RF-127.** Las operaciones frecuentes deberán requerir la menor cantidad posible de pasos.

**RF-128.** El sistema deberá permitir búsquedas rápidas por referencia, código de barras, cliente, pedido u otros criterios.

**RF-129.** El software deberá poder utilizarse adecuadamente desde computador y adaptarse a dispositivos móviles o tabletas cuando sea necesario.

---

## Resultado esperado

El objetivo principal del sistema es que gerencia y administración puedan tener control real sobre las dos empresas y eliminar la falta de información que actualmente existe.

El software deberá permitir conocer qué hay en inventario, qué entra, qué sale, qué se produce, cuánto se consume, cuánto se desperdicia, cuánto cuesta fabricar cada producto, cuánto se vende, por qué canal se vende, cuánto se gana y qué situaciones dentro de la empresa requieren atención.

Todo movimiento relevante deberá tener trazabilidad, responsable, fecha y soporte dentro del sistema.

---

## Regla de incorporación a Condor

Esta especificación representa el **input del cliente** y debe alimentar el diseño del producto, pero no convierte automáticamente cada detalle en una regla global del SaaS.

Para cada RF que llegue a implementación se deberá decidir explícitamente si corresponde a:

- una capacidad ya existente en Condor;
- una extensión reusable del núcleo;
- una configuración por tenant/empresa/razón social;
- una integración opcional mediante adaptador;
- o una necesidad específica que no deba imponerse a otros clientes.

La implementación deberá mantener la filosofía de Condor: **simple por defecto, altamente configurable, multi-tenant, con aislamiento de datos, permisos server-side y trazabilidad completa**.

## Mapa inicial por dominio

| Dominio | Requisitos |
| --- | --- |
| Multiempresa / razones sociales | RF-001–RF-004 |
| Inventario producto terminado | RF-005–RF-014 |
| Materias primas e insumos | RF-015–RF-024 |
| Producción | RF-025–RF-034 |
| Costos de producción | RF-035–RF-043 |
| Códigos de barras | RF-044–RF-047 |
| Ventas omnicanal | RF-048–RF-054 |
| Storefront Marcela Arias | RF-055–RF-059 |
| Web B2B Fabritex | RF-060–RF-063 |
| Mayoristas / detal | RF-064–RF-069 |
| Banco de fotos | RF-070–RF-073 |
| Pedidos | RF-074–RF-078 |
| Clientes | RF-079–RF-082 |
| Proveedores / compras | RF-083–RF-088 |
| Recursos humanos | RF-089–RF-093 |
| Asistencia / huellero | RF-094–RF-098 |
| Usuarios / roles / permisos | RF-099–RF-104 |
| Auditoría | RF-105–RF-108 |
| Panel gerencial | RF-109–RF-114 |
| Alertas | RF-115–RF-117 |
| Reportes | RF-118–RF-121 |
| Seguridad / respaldo | RF-122–RF-125 |
| UX / búsqueda / responsive | RF-126–RF-129 |
