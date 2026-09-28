# Condor App — Snapshot operativo · Password Recovery F V 0.1.71

> **Candidato objetivo:** V0.1.71 · Issue #301 · HTTP/UI segura para recuperación y cambio de contraseña.
>
> **Producción validada:** V0.1.70 · `main@c7020fcfb89631aab4fe8709117459c2fe5efe09` · release, observer y `/health` exact-main verdes.

Condor continúa en construcción. V0.1.71 implementa el sexto slice serial de #191: expone por HTTP los casos de uso de recuperación/cambio sin relajar seguridad ni 2FA.

## Alcance
- request y canje anónimos con CSRF, rate limiting por IP + identidad/token y respuesta anti-enumeración;
- cambio autenticado detrás de `ROLE_USER`, con CSRF y rate limit por usuario;
- respuestas sensibles con `Referrer-Policy: no-referrer` y `Cache-Control: no-store, private`;
- vistas Twig para solicitar, restablecer y cambiar contraseña, más enlace desde login;
- el token llega al navegador solo por fragmento, se copia a un campo POST y el fragmento se elimina del historial visible;
- regresiones HTTP y Playwright para navegación/renderizado sin 5xx.

## Seguridad
Las únicas rutas públicas nuevas son `/admin/recuperar-contrasena` y `/admin/restablecer-contrasena`. `/admin/seguridad/contrasena` sigue protegido por la regla general `^/admin`. Este slice no modifica 2FA, SMTP, secretos, checkout ni persistencia.

## Evidencia base
- #296–#300 completados en `main`;
- V0.1.70 / `main@c7020fcfb89631aab4fe8709117459c2fe5efe09` validada en producción antes de iniciar #301;
- `PasswordResetUrlFactory` ya garantiza origen HTTPS server-side + token en fragmento;
- fuente histórica #232 reutilizada selectivamente contra el wiring y UI actuales.
