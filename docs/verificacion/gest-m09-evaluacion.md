# GEST-M09-S06 · Evaluación exclusivamente ficticia de proveedores (#1579)

**Estado:** preparada en código de construcción; no autoriza go-live, datos reales,
proveedores externos, evaluación legal ni adjudicación. Dependencia #1534
fusionada mediante PR #1592. Se mantiene sin alterar la matriz global.

## API sintética de prueba

`POST /api/v1/branches/{branchId}/purchasing/suppliers/evaluations/preview`
requiere sesión, permiso `inventory.create`, CSRF `branch_access` y una sede
resuelta por servidor para el tenant activo. Solo existe en `test`, o en `dev`
con `CONDOR_PURCHASING_SYNTHETIC_API=1` y
`CONDOR_PRODUCTION_STAGE=construccion`; fuera de esas condiciones responde 404.

Cuerpo JSON objeto, máximo 65.536 bytes y profundidad 32:
- `tenant_ref=fixture:tenant/current`,
  `legal_entity_ref=fixture:legal-entity/current`,
  `site_ref=fixture:site/current` (sentinels, **no** identidades reales);
- `supplier_ref=fixture:supplier/demo`, `mode=preview_only`;
- `criteria`: exactamente tres objetos con `metric` diferente
  (`quality`, `delivery`, `compliance`), `score` entero de 0 a 5
  y los mismos tres ámbitos sintéticos.

Cada criterio pesa un tercio. El resultado es una proyección porcentual entera,
ordenada de forma determinista, con `status=REQUIRES_HUMAN_REVIEW` y
`can_award=false`, `can_approve=false`, `can_order=false`,
`can_pay=false`, `persisted=false`, `notified=false`.
**No clasifica, adjudica, contacta ni modifica proveedores reales.**
La nota numérica no es una recomendación ni decisión contractual.

## Evidencia y negativos

`tests/php/Application/Purchasing/SupplierEvaluationPreviewTest.php` comprueba
igualdad determinista, extremos 0/5, duplicados, campos/PII extra, números fuera
de rango, referencia de proveedor real y scope cruzado tenant/entidad/sede.
El escenario HTTP usa Symfony, usuarios/tenants/sedes de prueba, CSRF, JSON malformado (400), tamaño excesivo (400), 403
sin permiso, 404 por sede ajena y 422 por referencia/ámbito inválido.
Reversión: revert squash de este PR; no crea tablas, órdenes, pagos, llamadas
externas ni operaciones de escritura.

Verificación final exigida: `CI Factory v1 / Validar` en `success` sobre
el SHA exacto de la rama. Merge no equivale a despliegue ni certificación
productiva; no cambiar el estado de GEST-M09-S06 a cobertura live.
