# Condor App — Snapshot operativo · Password Recovery D V 0.1.69

> **Candidato objetivo:** V0.1.69 · Issue #299 · cola deferred y notificaciones de contraseña.
>
> **Producción validada:** V0.1.68 · `main@d4d586a6dfb7712acc9e07ebfd28e176ebc85d2c` · release, observer, CI y `/health` exact-main verdes.

Condor continúa en construcción. V0.1.69 implementa el cuarto slice serial de #191: cola transaccional en memoria y notificaciones de recuperación/cambio desacopladas del commit de credenciales.

## Alcance
- `DeferredTransactionalEmailQueue` in-memory con `drain()` one-shot;
- entrega best-effort en `kernel.terminate` mediante `TransactionalEmailGateway`;
- gateway indisponible o fallo de transporte no rompe el request ni la transacción de credenciales;
- `AccountPasswordNotifier` emite `account_password_reset` y `account_password_changed` sin construir URLs;
- `condor_password_reset` documentado como tratamiento técnico sin proveedor externo declarado;
- regresiones de queue, sanitización de logging y templates.

## Seguridad y datos
Este slice no persiste payloads de correo ni tokens en claro y no selecciona proveedor SMTP. El subscriber registra únicamente `template` de una lista cerrada y `reason`; recipient, reset URL, token, templateData y excepciones del transporte se descartan. `providers: []` mantiene el gateway real fail-closed hasta documentación explícita de un tercero.

## Evidencia base
- #296, #297 y #298 completados en `main`;
- V0.1.68 / `main@d4d586a6dfb7712acc9e07ebfd28e176ebc85d2c` exact-main GREEN antes de iniciar #299;
- `TransactionalEmailGateway::isAvailable()` y redacción SMTP integrados por PR #317;
- fuente revisada reutilizada selectivamente: PR histórico #232 / `618f57c0…`.
