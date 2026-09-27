# Condor App — Snapshot operativo · Password Recovery A V 0.1.65

> **Candidato objetivo:** V0.1.65 · Issue #296 · primitivas de credenciales y token de recuperación.
>
> **Producción desplegada y validada:** V0.1.64 · `main@df7a87059e90b4834e8f26480637906e7cfce1f5` · release/observer y gates exact-main verdes. **V0.1.65 aún no está desplegada ni validada en producción.**

Condor continúa en construcción. V0.1.65 inicia la reconstrucción serial de #191 sobre el main actual, reutilizando el trabajo revisado de PR #232 sin revivir su PR monolítico.

## Alcance
- entidad `PasswordResetToken` one-row-per-user, persistiendo solo SHA-256 del token;
- TTL de 60 minutos, estados `consumed` / `revoked` y reemisión explícita;
- `PasswordResetSecurity` genera 32 bytes aleatorios y valida tokens raw hex;
- `AccountPasswordPolicy` exige 12–4096 caracteres, rechaza contraseñas comunes, identidad del correo y reutilización;
- migración MariaDB aditiva para `condor_password_reset`, con rollback bloqueado si existen registros;
- regresiones directas de dominio/aplicación.

## Seguridad y datos
Este slice no envía correo, no crea rutas HTTP, no registra tokens raw y no selecciona proveedor SMTP. No ejecuta migraciones de producción desde el PR. La migración sigue el contrato expand-compatible de construcción y será aplicada por el flujo productivo normal con backup previo.

## Evidencia base
- `main@df7a87059e90b4834e8f26480637906e7cfce1f5` · V0.1.64.
- Parent #191 descompuesto en #296 → #302.
- Fuente reutilizada: PR histórico #232 / `618f57c0…`.
