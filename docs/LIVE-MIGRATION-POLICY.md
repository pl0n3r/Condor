# Condor · política de migraciones para fase live

Estado: **build-ahead durante construcción**. Este documento **no autoriza go-live**, no activa fase live, no habilita ejecución automática y no reemplaza la decisión D-054.

## Autoridad vigente

D-054 permanece activa en `decisiones.yml`. En particular, llegar a live no convierte una migración en una operación autónoma. El evaluador de este leaf solo responde si un cambio *podría ser presentado* a la puerta humana; nunca ejecuta una migración y siempre emite `auto_execute=false` y `authority=owner_gate_required`.

## Clasificación cerrada

La entrada solo puede terminar en una de tres clases:

- `additive`: cambio expand-compatible, sin retirada destructiva de estructura o datos.
- `destructive`: cambio que elimina, trunca, renombra de forma incompatible o destruye información/estructura.
- `unknown`: cualquier caso no demostrado, entrada desconocida o clasificación fuera del vocabulario.

`destructive` y `unknown` quedan bloqueados. La ausencia de evidencia nunca se interpreta como `additive`.

## Puertas para una candidata additive

Una candidata `additive` solo obtiene `eligible_after_owner_gate=true` cuando **todas** estas señales son booleanas y verdaderas:

1. dry-run válido;
2. allowlist completa;
3. backup receipt verificable;
4. post-check de readiness del servicio (`/health`) requerido;
5. post-check de schema requerido;
6. post-check de smoke requerido.

La elegibilidad significa exclusivamente “puede pasar a decisión humana”. No es permiso de ejecución.

## Abort conditions

Abortar y fallar cerrado cuando ocurra cualquiera de estas condiciones:

- clasificación `destructive` o `unknown`;
- dry-run ausente/fallido;
- allowlist incompleta;
- receipt de backup ausente o no verificable;
- falta cualquiera de service-readiness/schema/smoke;
- forma de evidencia inesperada o campos extra/no tipados como booleanos;
- el owner no ha aprobado explícitamente la operación live.

Un CI verde, un release, un deploy o esta evaluación **no** sustituyen la puerta humana.

## Forward-fix

Si una migración additive ya autorizada produce una degradación posterior, la estrategia preferida es un **forward-fix versionado** que preserve datos y vuelva a recorrer dry-run, allowlist, backup y post-checks. No se promueve una corrección improvisada ni una escritura fuera del flujo versionado.

## Restore y rollback

Este leaf no implementa rollback ni restore. Un restore solo puede plantearse a través del mecanismo de recovery ya verificado, con evidencia de backup válida, autoridad separada y ventana operativa explícita. No se promete rollback destructivo automático: restaurar sobre un esquema ya mutado puede no revertir objetos creados después del backup.

Para cualquier recuperación destructiva se conserva la frontera existente de `.github/workflows/recovery-production.yml` y su propia autoridad. La política aquí definida no llama ni dispara ese workflow.

## Contrato del evaluador

`scripts/live-schema-policy.php` es un evaluador puro: recibe un objeto JSON por stdin y devuelve un objeto JSON. No abre conexiones, no lee variables de entorno, no ejecuta comandos, no escribe archivos y no toca el runtime productivo.

Ejemplo de candidata completa:

```json
{
  "classification": "additive",
  "dry_run_valid": true,
  "allowlist_complete": true,
  "backup_receipt_verified": true,
  "post_checks": {
    "service_readiness": true,
    "schema": true,
    "smoke": true
  }
}
```

Incluso en ese caso la salida mantiene `auto_execute=false`; el único cambio es `eligible_after_owner_gate=true`.

## Reversión del leaf

El cambio es código de política + documentación + prueba y un bump de identidad de release. Revertir el PR elimina el evaluador sin tocar esquema, datos, Hostinger ni fase productiva.
