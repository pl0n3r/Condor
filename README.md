# Condor App — Snapshot operativo · Password Recovery C V 0.1.68

> **Candidato objetivo:** V0.1.68 · Issue #298 · gateway SMTP transaccional fail-closed.
>
> **Producción validada:** V0.1.67 · `main@c7a4d6c4e9d4a0c7f649c6c89f6a09586ff6477f` · release, observer, CI y `/health` exact-main verdes.

Condor continúa en construcción. V0.1.68 implementa el tercer slice serial de #191: transporte SMTP server-side configurable sin seleccionar ni contratar proveedor externo.

## Alcance
- adapter `SymfonyMailerTransactionalEmailGateway` compatible con `isAvailable()`;
- solo SMTP/SMTPS, remitente válido y provider documentado explícitamente para `condor_password_reset`;
- configuración únicamente por `CONDOR_MAILER_DSN`, `CONDOR_MAIL_FROM` y `CONDOR_MAIL_PROVIDER`;
- wiring reproducible de Symfony DI sobre la factory ya integrada;
- errores de configuración/transporte fail-closed y sanitizados;
- regresiones directas de envío, indisponibilidad y no-filtración.

## Seguridad y datos
Este slice no elige proveedor, no modifica `datos.yml`, no inventa base legal y no registra destinatarios, tokens, payloads ni DSN. Mientras no exista un provider documentado en `datos.yml`, el gateway permanece indisponible en producción.

## Evidencia base
- #296 y #297 completados en `main`;
- Symfony Mailer/factory reproducible integrado por PR #306;
- fuente revisada reutilizada selectivamente: PR histórico #232 / `618f57c0…`;
- V0.1.67 exact-main validada antes de iniciar #298.
