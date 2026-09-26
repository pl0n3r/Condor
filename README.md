# Condor App — Snapshot operativo · API staff ControlBot V 0.1.51

> **Candidato:** Issue #235, API M2M segura para administración acotada de staff por ControlBot.

Condor continúa en **construcción**. V0.1.51 añade la superficie `/ops` para ControlBot con autenticación HMAC, anti-replay, allowlist, rate limit, auditoría local y frontera estricta sobre staff no-owner; la integración queda desactivada por defecto. Se conserva la reducción de fan-out integrada en V0.1.49.

## Alcance
- autenticación M2M HMAC SHA-256 con timestamp, nonce anti-replay, allowlist IP y rate limiting;
- resumen y búsqueda de staff con correo enmascarado, sin clientes ni usuarios tenant;
- owner de plataforma fuera del dominio mutable de ControlBot;
- invitación sin contraseñas ni tokens en respuesta y fail-closed si no existe entrega transaccional real;
- suspensión, reactivación y rol de staff con mutación + auditoría dentro de la misma transacción;
- password reset bloqueado hasta integrar #191;
- tratamiento `controlbot_staff_operations` documentado sin inventar proveedor o base legal.

## Seguridad y reversión
El slice conserva aislamiento por tenant y entidad, no ejecuta SQL de producción ni modifica secretos o infraestructura. La migración es aditiva; cualquier despliegue requiere el flujo normal de backup, migración versionada y observación productiva. Revertir este candidato retira la superficie M2M de staff sin alterar la telemetría/coordinación integrada en V0.1.49.

## Evidencia base
- `main@479247d01aa862cff24ee0b2dcc360fdb1762ff2` · V0.1.50.
- Rama recuperada canónicamente para #235 / PR #255; candidato V0.1.51.
- Versión desplegada: **no verificada en este PR** · producción validada: **pendiente**.
- Merge/CI verde no equivale automáticamente a producción validada.
- Factory v1, Política, Privacidad, PHP/MariaDB y coordinación deben pasar sobre el HEAD exacto.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #235
