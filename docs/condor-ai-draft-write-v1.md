# Condor AI Draft Write Runtime v1

## Propósito

El runtime de draft writes conecta las dos herramientas reversibles ya permitidas con `AiConversationCore` sin ampliar la autoridad del asistente:

- `content.draft.update`
- `settings.draft.update`

`AiDraftWriteRegistry` conserva el contrato cerrado de inputs/outputs. `AiConversationCore` conserva decisión, auditoría, receipt minimizado, handoff y control de replay.

## Regla de ejecución

Toda ejecución de estas herramientas tiene riesgo `reversible_write` y requiere una instancia de `AiToolReplayGuard`.

Flujo:

1. construir el registry únicamente con las dos draft writes canónicas y handlers `Closure` inyectados;
2. entregar el turno al `AiConversationCore`;
3. autorizar mediante `AiToolPolicy`;
4. reclamar la replay key antes de ejecutar el handler;
5. validar inputs y outputs con `AiDraftWriteContract`;
6. devolver solo resultado validado, audit y receipt minimizado.

Si falta el replay guard, el core devuelve `tool_replay_guard_required` con `executed=false`. Si la misma replay key ya fue reclamada, devuelve `tool_replay_detected` y el handler no se ejecuta otra vez.

## Fallo cerrado

Se produce handoff o denegación sin ampliar autoridad ante:

- tenant distinto al contexto activo;
- tool desconocida o no autorizada;
- handlers faltantes, extra o no `Closure`;
- input u output fuera del contrato;
- conflicto de replay;
- fallo del handler;
- receipt/auditoría no canónicos.

Los fallos no exponen replay keys internas, claims ni payload libre.

## Límites

Este runtime es local y provider-neutral. No añade:

- DB, Redis o ledger distribuido;
- filesystem o red;
- proveedor/modelo/canal;
- contenido real o PII;
- secretos, credenciales o gasto;
- deploy, producción o go-live;
- bypass de policy, decisión humana o replay guard.

El guard actual es local e in-memory. Persistencia o coordinación distribuida de replay quedan fuera de este contrato.
