# M09/S03 — solicitud de compra ficticia (solo vista previa)

Implementación de **preparación offline reversible**, autorizada por la puerta Condor #1317/A para #1424. No representa una compra real ni valida `GEST-M09-S03` en producción.

`App\Application\Purchasing\PurchaseRequestPreview::preview` recibe exclusivamente referencias tipadas `fixture:tenant/`, `fixture:legal-entity/`, `fixture:site/`, `fixture:item/` y, cuando aplica, `fixture:quotation/`; todas las líneas mantienen el mismo ámbito. Rechaza entradas incompletas, campos imprevistos, cantidades no decimales o fuera de 0.001–1000.000 y artículos duplicados. Admite una cotización M09/S02 opcional, **también ficticia y del mismo ámbito**. El resultado es determinista y solo contiene 15 campos autorizados, `status=pending`, `mode=preview_only` y banderas de compra, aprobación, pago, despacho, persistencia y notificación siempre falsas.

No hay HTTP, DB, modelos de tenant reales, facturación, obligaciones tributarias, creación de órdenes, credenciales, formularios, envío ni servicios externos. El test invoca **PHP CLI real**, no simula la ejecución de PHP; corre por discovery `test_ci_*.py` con cuatro casos AC-01..AC-04.

Pruebas locales: `php -n -l src/Application/Purchasing/PurchaseRequestPreview.php` y `python3 -m unittest discover -s tests -p 'test_ci_m09_purchase_request_preview.py' -v`. Estas pruebas son **offline** y no sustituyen CI exact-HEAD de GitHub, revisión final, respaldo/restore, HEALTH ni permiso para operaciones reales. Para revertir el eventual squash, eliminar las cuatro rutas de #1424.
