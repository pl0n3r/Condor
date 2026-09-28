# Condor App — Snapshot operativo · Password Recovery E V 0.1.70

> **Candidato objetivo:** V0.1.70 · Issue #300 · casos de uso reset y cambio autenticado.
>
> **Producción validada:** V0.1.69 · `main@3045546cfa324a70fecf6421b9e3f1f7ac1415b2` · release, observer y `/health` exact-main verdes.

Condor continúa en construcción. V0.1.70 implementa el quinto slice serial de #191: casos de uso de recuperación y cambio autenticado sobre las primitivas y notificaciones ya integradas.

## Alcance
- `PasswordResetUrlFactory` usa únicamente un origen HTTPS canónico server-side y coloca el token en fragmento;
- solicitud de reset con respuesta de aplicación uniforme, reemisión que sustituye el hash anterior y lock por usuario;
- finalización one-shot con lock pesimista, política de contraseña, rotación de hash y auditoría sin secretos;
- cambio autenticado exige contraseña actual, revoca reset pendiente y audita la rotación;
- notificaciones se encolan después del commit y reutilizan la cola deferred de #299;
- regresiones MariaDB/Kernel para reemisión, consumo, cambio válido y credencial actual inválida.

## Seguridad y datos
El token crudo nunca se persiste: `condor_password_reset` conserva únicamente SHA-256 y lifecycle. La URL no deriva host/origen del request y el token no entra en path ni query. Estos casos de uso no desactivan 2FA, no eligen proveedor SMTP y no añaden rutas HTTP o UI.

## Evidencia base
- #296–#299 completados en `main`;
- V0.1.69 / `main@3045546cfa324a70fecf6421b9e3f1f7ac1415b2` validada en producción antes de iniciar #300;
- `AccountPasswordNotifier` recibe reset URL preparada y no token crudo;
- fuente histórica #232 reutilizada selectivamente contra los contratos actuales.
