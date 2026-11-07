# GEST-M09-S08 · Cuentas por pagar ficticias (#1581)

**Estado:** proyección de obligaciones no conciliadas y no pagadas, sin libro
contable, SQL comercial, proveedores reales, datos personales o pagos.
Depende de #1580 (contrato de proveedor, cerrado y fusionado). No cambia
`docs/matriz-cobertura.md` ni certifica operaciones productivas.

## API sintética y autoridad

`POST /api/v1/branches/{branchId}/purchasing/payables/preview`
solo responde en `test` o en `dev` con
`CONDOR_PURCHASING_SYNTHETIC_API=1` y
`CONDOR_PRODUCTION_STAGE=construccion`; los demás contextos responden 404.
Requiere sesión, permiso `inventory.create`, CSRF `branch_access` y
sede/tenant comprobados por el servidor. No se consulta ninguna tabla
de cuentas, pagos, proveedores, bancos o movimientos de clientes.

JSON objeto de máximo 65.536 bytes, profundidad 32, campos exactos:
- `tenant_ref=fixture:tenant/current`,
  `legal_entity_ref=fixture:legal-entity/current`,
  `site_ref=fixture:site/current` (centinelas, nunca identidades reales);
- `mode=preview_only`, `obligations`: entre 1 y 20 objetos;
- cada obligación tiene `obligation_ref=fixture:payable/<slug>`,
  `supplier_ref=fixture:supplier/<slug>`, los tres centinelas de scope,
  `amount_cop` (cadena de 1 a 12 dígitos decimales, positiva, sin
  separadores, ceros a la izquierda ni centavos), `currency=COP`,
  `reconciliation_status=unreconciled`, `payment_status=unpaid`.

Se rechazan duplicados, scopes cruzados, importes numéricos o en otras
monedas, estados pagados/conciliados, campos extra, PII y referencias externas.
La proyección ordena por obligación y suma COP como cadena, sin efectos.
Siempre `can_pay=false`, `can_reconcile=false`, `can_settle=false`,
`payments_created=0`, `accounting_posted=false`, `persisted=false`
y `notified=false`. Ningún endpoint genera pagos ni asientos.

## Pruebas, límites y reversión

`tests/php/Application/Purchasing/PayablesPreviewTest.php` cubre importe
exacto/determinista y negativos de currency, estado, scope anidado,
PII/proveedor real, campos extra, duplicación y entradas inválidas.
HTTP prueba 401 sin login, 403 sin CSRF/permiso, 404 sede ajena, 400 JSON
corrupto o tamaño excesivo, 422 scope/contrato inválido y 200 solo sintético.

Reversión: revert del squash; no migraciones, gastos, secretos ni despliegues.
La aceptación final exige reserva V3 auténtica, `CI Factory v1 / Validar`
terminal success sobre HEAD exacto, verificación de seguridad y revisión
FINAL aplicable. Fusionar no equivale a go-live.
