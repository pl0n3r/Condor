# GEST-M09-S07 · Registro sintético de contrato de proveedor (#1580)

**Estado:** código exclusivamente de construcción, sin validez jurídica, firma,
notificación, persistencia, pago ni aprobación automática. La dependencia #1579
se fusionó por PR #1698; la matriz de cobertura global permanece sin cambios.

## Superficie HTTP protegida

`POST /api/v1/branches/{branchId}/purchasing/suppliers/contracts/preview`
requiere sesión, `inventory.create`, CSRF `branch_access`, sede y tenant validados
por el servidor. Se habilita únicamente en `test` o en `dev` con
`CONDOR_PURCHASING_SYNTHETIC_API=1` y `CONDOR_PRODUCTION_STAGE=construccion`.
En otros contextos responde 404; no consulta proveedores reales.

JSON objeto con máximo 65.536 bytes y profundidad 32, campos cerrados:
- `tenant_ref=fixture:tenant/current`, `legal_entity_ref=fixture:legal-entity/current`,
  `site_ref=fixture:site/current`: centinelas, nunca identidades de clientes;
- `supplier_ref=fixture:supplier/demo`, `contract_ref=fixture:contract/example`,
  `mode=preview_only`;
- `metadata`: `template_ref=fixture:contract-template/standard`,
  `kind=supplies|services` y `revision` entero 1..99;
- `reviews`: exactamente tres pasos sin repetición (`purchasing`, `legal`,
  `compliance`) con `state=pending|changes_requested|reviewed` y los mismos
  centinelas de tenant/entidad/sede por paso.

Se normaliza el orden de revisión y siempre devuelve
`status=REQUIRES_HUMAN_REVIEW`, `can_approve=false`, `can_sign=false`,
`can_send=false`, `can_order=false`, `juridically_binding=false`,
`persisted=false`, `notified=false`, **incluso si los tres pasos están revisados**.
No admite textos libres, nombres/correos, PII, firmas, documentos ni URLs.

## Tests y reversión

`tests/php/Application/Purchasing/SupplierContractPreviewTest.php` prueba
proyección determinista, estados fail-closed, scope cruzado tenant/entidad/sede,
referencias reales, duplicados, firmas y campos extra. HTTP Symfony cubre
401 no autenticado, 403 sin CSRF/permiso, 400 JSON/tamaño, 404 sede ajena,
422 payload inválido y 200 sin autoridad legal.

La reversión es revert del squash, sin cambios a DB/infra. La aceptación
exige `CI Factory v1 / Validar` terminal verde en HEAD exacto, V3 y revisión
FINAL aplicable. Merge no equivale a deployment, contrato jurídicamente
válido ni verificación productiva.
