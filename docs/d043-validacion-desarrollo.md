# D-043 durante la fase de construcción

## Autoridad

Durante la fase `construccion`, la decisión del propietario permite sustituir la confirmación humana por versión por **auto-validación con evidencia**. Esta excepción no autoriza el go-live: antes de live sigue siendo obligatoria una revisión consolidada del propietario en Condor#389.

La fase se declara de forma explícita en el workflow como `construccion`. Cualquier otro valor desactiva la promoción automática.

## Evidencia mínima

Una release solo puede pasar a `VALIDATED_IN_PRODUCTION` automáticamente si toda la evidencia corresponde al mismo SHA y versión:

1. `/health` coincide exactamente y reporta estado correcto;
2. `schema_up_to_date=true`;
3. todos los smoke públicos exigidos por el manifiesto están en verde;
4. el manifiesto D-043 es válido y sus transiciones requeridas son auto-verificables;
5. `/post-deploy-status.php` coincide con el mismo SHA/versión y reporta `phase=complete`, `result=success`;
6. no existe migración destructiva o ambigua;
7. la fase sigue siendo `construccion`.

Las transiciones auto-verificables están acotadas a `migraciones`, `comandos` y `cache`. `roles` o `configuracion` mantienen el camino humano. Cuando el acumulado histórico contiene migraciones cuya seguridad no puede demostrarse desde el checkout actual, se consideran ambiguas y la auto-validación falla cerrado.

## Bootstrap de V0.1.137

El cambio que introduce esta política no incrementa `config/version.php` porque #494 conserva el claim de versión para V0.1.138. Por eso el observer intenta primero validar la **baseline previa** todavía desplegada (`github.event.before`) antes de esperar un nuevo deploy.

Ese bootstrap:

- solo corre si el push sin bump modifica exclusivamente los cuatro archivos de #502;
- exige que la identidad previa figure realmente pendiente en D-043;
- reconstruye el manifiesto acumulado de las versiones anteriores;
- observa la baseline previa sin mutar producción;
- publica evidencia solo si todos los gates automáticos pasan;
- si producción ya cambió de SHA o la evidencia no es concluyente, no publica una validación falsa.

El push bootstrap no se registra como una segunda release con la misma versión, evitando crear dos identidades canónicas V0.1.137 con SHAs distintos.

## Registro e idempotencia

Cada éxito usa un marker estable `condor-d043-dev-auto` ligado a versión+SHA. El workflow actualiza el comentario existente si el marker ya existe, en vez de duplicarlo.

La misma evidencia se registra en:

- Condor#1, como historial operativo D-043;
- Condor#389, como lista revisable para el checkpoint consolidado pre-live.

La etiqueta visible es **validación automática de desarrollo**, para no confundirla con una confirmación humana.

## Fail-closed y reversión

Si falta identidad exacta, schema, smoke, post-deploy completo, fase válida o seguridad de la migración, el estado permanece `DEPLOY_OBSERVED` y se conserva la tarjeta humana existente. El mecanismo no escribe en producción, no ejecuta migraciones y no cambia secretos.

Revertir este cambio restaura el comportamiento D-043 anterior.
