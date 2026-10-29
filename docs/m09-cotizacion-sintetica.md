# M09/S02 — Cotización de compra exclusivamente ficticia

## Autoridad y límites
El OWNER autorizó únicamente la preparación sintética mediante Condor #1178, voto A y journal v2. Esta hoja #1315 está limitada a cuatro archivos nuevos y depende de #685. La implementación no altera controladores, rutas HTTP, la base de datos, cuentas de proveedores ni producción.

## Contrato
La clase PHP App\Application\Purchasing\QuotationPreview::preview acepta exactamente seis claves de entrada: tenant_ref, legal_entity_ref, site_ref, currency, mode y lines. Los identificadores son referencias inventadas con prefijo fixture:. Se rechazan claves adicionales, cruces de tenant, entidad o sede, cantidades inválidas, líneas repetidas, y solicitudes de orden, aprobación, pago o envío.

La moneda única es COP. Cantidades y precios son cadenas decimales estrictas (hasta tres y dos decimales, respectivamente). La multiplicación usa enteros escalados, el redondeo half-up se realiza por línea al peso entero y se suman los totales redondeados. Existen límites explícitos por importe, total y cantidad de líneas; los errores no entregan resultados parciales.

## Sin efectos operativos
La salida indica SYNTHETIC_PREVIEW y preview_only. Todos los flags can_order, can_pay, can_approve, can_dispatch y persisted son false. No hay órdenes, pagos, contactos externos, datos personales, proveedores ni validaciones tributarias o jurídicas reales.

## Prueba y reversión
Ejecutar: python3 -m unittest discover -s tests -p 'test_ci_m09_quotation_preview.py' -v. Las cuatro pruebas realizan PHP CLI real con php -n, sin acceso a red. El CI Validar y los análisis de seguridad del HEAD exacto constituyen gates de la integración. Revertir el squash elimina exclusivamente los cuatro archivos. La matriz de cobertura permanece NO_VERIFICABLE hasta una reconciliación separada; ninguna prueba offline reemplaza D-043, seguridad live o autorización de publicación.
