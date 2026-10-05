# Condor AI — Commerce Read Runtime v1

`AiCommerceReadRuntime` compone los contratos comerciales read-only sobre el `AiConversationCore` existente.

## Alcance

El runtime acepta únicamente los tools comerciales `catalog.read` e `inventory.read`. Los handlers se inyectan como `Closure` a través de `AiCommerceReadRegistry`, que conserva la validación canónica de inputs y outputs definida en `AiCommerceReadContract`.

La ejecución sigue pasando por `AiConversationCore`, por lo que se mantienen sin bifurcación `AiToolDecision`, auditoría, receipt minimizado y handoff. El runtime no introduce replay guard porque ambos tools mantienen riesgo `read_only` en `AiToolPolicy`.

## Límites de autoridad

La composición es provider-neutral y no accede a base de datos, red, APIs externas ni datos reales. No registra tools adicionales, no habilita escritura y no crea un camino alterno alrededor de la policy existente.

Cross-tenant, tool no registrada, input no canónico, registry incompleto o output inválido terminan en deny/handoff antes de aceptar evidencia como resultado exitoso.

## Evidencia ejecutable

- `AiCommerceReadRuntimeTest` cubre catálogo/inventario, receipt minimizado, rechazos y repetición read-only sin replay.
- `tests/test_condor_ai_commerce_read_core.py` enlaza los criterios de aceptación del Issue con esos casos PHPUnit y valida la ausencia de dependencias de infraestructura en el runtime.
