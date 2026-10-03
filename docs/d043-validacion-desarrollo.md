# D-043 durante la fase de construcción

## Autoridad

Durante la fase `construccion`, la decisión del propietario permite sustituir la confirmación humana por versión por **auto-validación con evidencia**. Esta excepción no autoriza el go-live: antes de live sigue siendo obligatoria una revisión consolidada del propietario en Condor#389.

La fase se deriva de la puerta canónica Condor#389: solo `open` + `estado: bloqueado` (go-live aún no autorizado) resuelve `construccion`. Cualquier otro estado resuelve `desconocida` y desactiva la promoción automática.

## Evidencia mínima

Una release solo puede pasar a `VALIDATED_IN_PRODUCTION` automáticamente si toda la evidencia corresponde al mismo SHA y versión:

1. `/health` coincide exactamente y reporta estado correcto;
2. `schema_up_to_date=true`;
3. todos los smoke públicos exigidos por el manifiesto están en verde;
4. el manifiesto D-043 es válido y sus transiciones requeridas son auto-verificables;
5. `/post-deploy-status.php` coincide con el mismo SHA/versión y reporta `phase=complete`, `result=success`;
6. no existe migración destructiva o ambigua;
7. la fase sigue siendo `construccion`.

Las transiciones candidatas están acotadas a `migraciones`, `comandos` y `cache`, pero además se exige procedencia. Las migraciones se consideran **ambiguas por defecto** y permanecen en camino humano hasta existir un clasificador determinista de destructividad. `comandos` solo se auto-resuelve cuando todos los paths que originan ese flag pertenecen a la allowlist mínima de tooling observacional (`scripts/release_evidence.py` y `scripts/d043_pending_releases.py`); cualquier `bin/console`, `src/Console/` o script de backfill/deploy/migrate/provision/release no allowlisted falla cerrado. `roles` y `configuracion` siempre mantienen el camino humano.

## Bootstrap de la baseline previa y V0.1.138

Esta política entra como **V0.1.138**. Condor mantiene identidades SemVer de un solo uso, así que el cambio lleva su propio bump y no reutiliza el tag V0.1.137.

En cada bump, el observer intenta primero reconciliar la **baseline previa** indicada por `github.event.before` cuando esa identidad sigue pendiente en D-043. Para este cambio, la baseline es V0.1.137. La reconciliación previa y la observación de la release nueva son pasos distintos: validar la anterior nunca omite el smoke/observer normal de V0.1.138.

Ese bootstrap previo:

- solo actúa cuando la versión anterior es distinta de la nueva y la identidad anterior figura realmente pendiente en D-043;
- reconstruye el manifiesto acumulado de las versiones anteriores;
- observa la baseline previa sin mutar producción;
- publica evidencia solo si todos los gates automáticos pasan;
- si producción ya cambió de SHA o la evidencia no es concluyente, no publica una validación falsa;
- después continúa con la observación normal de la versión nueva.

Se conserva el modo `bootstrap_only` histórico para compatibilidad fail-closed, pero un bump usa `bootstrap_previous` y **no** salta los pasos de la release actual.

## Registro e idempotencia

Cada éxito usa un marker estable `condor-d043-dev-auto` ligado a versión+SHA. El workflow actualiza el comentario existente si el marker ya existe, en vez de duplicarlo.

La misma evidencia se registra en:

- Condor#1, como historial operativo D-043;
- Condor#389, como lista revisable para el checkpoint consolidado pre-live.

La etiqueta visible es **validación automática de desarrollo**, para no confundirla con una confirmación humana.

## Fail-closed y reversión

Si falta identidad exacta, schema, smoke, post-deploy completo, fase válida o procedencia segura de la transición, el estado permanece `DEPLOY_OBSERVED` y se conserva la tarjeta humana existente. El mecanismo no escribe en producción, no ejecuta migraciones y no cambia secretos.

Revertir este cambio restaura el comportamiento D-043 anterior.
