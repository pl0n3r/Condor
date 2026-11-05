# Condor · GEST-M09 · Compras y proveedores (Issue #1534)

Corte de verificación: 2026-11-05, `construccion`. Solo fixtures sintéticos;
**sin go-live, despliegue, proveedores externos, compra/pago real, datos de clientes
ni aprobación o validez jurídica automática**. La matriz consolidada
`docs/matriz-cobertura.md` queda intacta.

La matriz original muestra ocho filas `NO_VERIFICABLE`. Un preview puro no
prueba por sí solo un recorrido funcional HTTP/UI ni autoriza promocionar una
fila. Se distingue evidencia completa de preparación parcial.

| Fila | Implementación y ruta HTTP | Aserción exacta y PR fusionado de base | Resultado / brecha restante |
| --- | --- | --- | --- |
| `GEST-M09-S01` proveedores | `SupplierService`, `Supplier`; GET/POST `/api/v1/branches/{branchId}/purchasing/suppliers` | `tests/test_purchasing_http_api.py::ComprasApiHttpTests::test_acceptance`; `tests/php/Domain/Purchasing/SupplierDomainTest.php`; #658, #700 y #709 MERGED | **CUBIERTO en código/HTTP/UI existente**, con aislamiento tenant, `inventory.view/create`, CSRF y estados UI probados por `tests/test_purchasing_admin_ui.py`. El test nuevo `GestM09VerificationTest::testS01S04S05CannotCrossTenantOrMutateReceivedOrder` añade regresión de invariantes. |
| `GEST-M09-S02` cotización | `QuotationPreview::preview`; POST `/api/v1/branches/{branchId}/purchasing/quotations/preview`, solo test o dev con bandera | #1316 MERGED; `GestM09VerificationTest::testS02QuotationIsSyntheticOnlyAndCannotPlaceOrders`, `testS02RejectsForeignTenantAndUnexpectedFields`, `testPreviewHttpBoundaryRequiresPermissionsCsrfAndConstructionFlag` | **PARCIAL / NO_VERIFICABLE integral**: preview ficticio, `inventory.create`, CSRF y scope real antes del cálculo; faltan prueba HTTP autenticada de negativos y UI con cuatro estados: #1578. |
| `GEST-M09-S03` solicitud | `PurchaseRequestPreview::preview`; POST `/api/v1/branches/{branchId}/purchasing/requests/preview`, solo test o dev con bandera | `GestM09VerificationTest::testS03RequestIsPendingAndNeverPaysOrApproves`, `testS03RejectsForeignEntityDuplicateAndPersonalData`, `testPreviewHttpBoundaryRequiresPermissionsCsrfAndConstructionFlag` | **PARCIAL / NO_VERIFICABLE integral**: no crea orden, aprueba, paga, despacha, persiste ni notifica. Faltan prueba HTTP autenticada y UI con estados: #1578. |
| `GEST-M09-S04` orden | `PurchaseOrderService`, `PurchaseOrder`; GET/POST `/api/v1/branches/{branchId}/purchasing/orders` | `tests/test_purchasing_http_api.py`; `tests/php/Domain/Purchasing/PurchaseOrderDomainTest.php`; #700 y #709 MERGED | **CUBIERTO en código/HTTP/UI existente** para orden de materiales de tenant/entidad autorizados, `inventory.view/create`, CSRF, validación de líneas y estados loading/empty/error/denied. No equivale a flujo de aprobación de compras. |
| `GEST-M09-S05` recepción | `PurchaseOrderService::receive`; POST `/api/v1/branches/{branchId}/purchasing/orders/{orderId}/receive` | `tests/test_purchasing_http_api.py`; `tests/php/Domain/Purchasing/PurchaseOrderDomainTest.php`; #700 y #709 MERGED | **CUBIERTO en código/HTTP/UI existente** para recepción idempotente en inventario, `inventory.update`, CSRF, tenant/entidad/sede y bloqueo; no acredita acta externa de aceptación. |
| `GEST-M09-S06` evaluación | Sin servicio ni endpoint especializado | Sin prueba de aceptación integral | **BRECHA / NO_VERIFICABLE**: evaluación trazable y autorizada pendiente en #1579. |
| `GEST-M09-S07` contrato | Sin servicio ni endpoint especializado | Sin prueba de aceptación integral | **BRECHA / NO_VERIFICABLE**: registro ficticio y revisión jurídica pendiente en #1580; sin firma ni validez automática. |
| `GEST-M09-S08` cuentas por pagar | Sin libro ni conciliación de CxP especializado | Sin prueba de aceptación integral | **BRECHA / NO_VERIFICABLE**: proyección aislada sin pagos pendientes en #1581. |

## Puertas, evidencia y límites del corte

- La API existente de S01/S04/S05 resuelve tenant y sede en servidor mediante
  `BranchApiSupport::authorizedBranchScope`. GET usa `inventory.view`;
  POST exige `inventory.create/update` y CSRF. La prueba HTTP real
  `test_purchasing_http_api.py` incluye tenant ajeno (404), sede negada (403),
  proveedor ajeno y mutación sin permiso (403).
- La UI existente `frontend/admin/PurchasingManagement.tsx` maneja
  loading/empty/error/permission-denied **solo para proveedores, órdenes y
  recepción**. No atribuir esos cuatro estados a cotización/solicitud.
- Los dos nuevos POST de previsualización son solo de test o dev con
  `CONDOR_PURCHASING_SYNTHETIC_API=1` y
  `CONDOR_PRODUCTION_STAGE=construccion`. El servidor comprueba permisos,
  CSRF y sede real y luego restringe las referencias a
  `fixture:tenant/current`, `fixture:legal-entity/current` y
  `fixture:site/current` antes de aceptar líneas anidadas. En prod
  retornan 404 y jamás llaman a proveedores externos.
- Las nuevas pruebas PHPUnit son unitarias/de contrato de código; **no son**
  una prueba HTTP con sesión real ni prueban la UI de previews. Las brechas
  continúan explícitas hasta las hojas hijas y sus PRs fusionados.
- Comprobaciones previstas: `vendor/bin/simple-phpunit --filter GestM09VerificationTest`,
  `python -m unittest tests.test_purchasing_http_api tests.test_purchasing_admin_ui`,
  y `CI Factory v1 / Validar` terminal sobre el SHA exacto del PR. Una ejecución
  aún no observada no se declara verde aquí.

Reversión de esta tanda: revertir el squash del PR de #1534. Ninguna de las
hojas #1578–#1581 debe tomarse antes de resolver su dependencia y reserva V3.
