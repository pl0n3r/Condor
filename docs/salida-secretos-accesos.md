# Secretos y accesos de salida a producción de Condor (#983)

## Propósito, alcance y autoridad

Inventario **sin valores** de configuración, credenciales y permisos de Condor para preparar su salida a producción. Este documento **no autoriza** crear cuentas, leer secretos, modificar proveedores, activar servicios, acceder a datos reales ni hacer go-live. Fuentes: docs/lista-salida-produccion.md, docs/LIVE-AUTH-SMOKE-RUNBOOK.md, docs/sentry-datos-minimos.md, docs/recovery-production.md, .github/workflows/deploy-factory.yml y config/packages/doctrine.yaml.

**Fase:** construccion. Los nombres siguientes identifican variables o accesos, no son valores ni prueban que estén configurados. Toda rotación o revocación **real**, proveedor, gasto, acceso, cuenta o cambio de fase requiere aprobación explícita del **DUEÑO**.

## Inventario de secretos y accesos (sin valores)

| Nombre o acceso | Propósito | Dónde vive / custodia prevista | Responsable | Rotación planificada | Revocación planificada |
| --- | --- | --- | --- | --- | --- |
| DATABASE_URL | Acceso MariaDB | Runtime seguro Hostinger y GitHub Actions Secrets del deploy autorizado | DUEÑO / administrador DB | Sustituir usuario/credencial en ventana aprobada, verificar conexión y retirar anterior | Deshabilitar usuario antiguo y sus sesiones |
| APP_SECRET | Firmas y sesiones Symfony | Variable segura en runtime Hostinger, nunca Git | DUEÑO / operador backend | Cambiar en ventana aprobada, anticipar cierre de sesiones y validar login | Invalidar valor y sesiones anteriores con autorización |
| MAILER_DSN / cuenta SMTP (pendiente) | Notificación transaccional y recuperación de cuenta | Runtime protegido y consola de proveedor por elegir | DUEÑO / operador correo | Crear credencial mínima, probar sintéticamente, sustituir y retirar antigua | Desactivar credencial de proveedor |
| SENTRY_DSN (pendiente) | Telemetría mínima de errores | Cuenta Sentry y runtime solo tras aprobación de proveedor y privacidad | DUEÑO / observabilidad | Sustituir DSN aprobado y validar solo estado de integración | Desactivar DSN/proyecto antiguo |
| DEPLOY_SSH_KEY | Llave del despliegue SSH | GitHub Actions Secrets; contraparte pública en Hostinger | DUEÑO / infraestructura | Crear par nuevo fuera de Git, autorizar clave pública, actualizar Secret y probar antes de retirar | Quitar clave pública antigua y Secret asociado |
| HOSTINGER_KNOWN_HOSTS | Identidad SSH del servidor, no contraseña | GitHub Actions Secrets según deploy-factory.yml | DUEÑO / infraestructura | Renovar tras comprobar huella por canal independiente | Retirar huella obsoleta del workflow |
| HOSTINGER_SSH_HOST, HOSTINGER_SSH_USER, HOSTINGER_SSH_PORT, HOSTINGER_RELEASE_ROOT | Parámetros de conexión y destino; no son claves secretas | GitHub Actions Variables | DUEÑO / infraestructura | Revisar cuando cambie alojamiento o usuario | Retirar acceso SSH y usuario obsoleto |
| GITHUB_TOKEN (efímero) | Permisos del workflow | Token temporal emitido por GitHub Actions, no se almacena manualmente | DUEÑO / administrador GitHub | Emitido por job, revisar permisos mínimos | Cancelar ejecución, restringir permisos y revocar accesos de actores |
| CONDOR_SMOKE_EMAIL, CONDOR_SMOKE_PASSWORD (sintéticos) | Smoke autenticado read-only | GitHub Actions Secrets; identidad sintética, nunca cliente real | DUEÑO / QA | Cambiar clave de identidad sintética y Secret fuera de logs | Deshabilitar cuenta sintética y eliminar Secrets |
| CONDOR_AUTH_SMOKE_ENABLED (variable) | Puerta explícita para ejecutar smoke autenticado | GitHub Actions Variables; apagada por defecto | DUEÑO | No aplica rotación de secreto; revisar autorización | Desactivar y comprobar que jobs quedan omitidos |
| CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE, RECOVERY_DISPOSABLE_DATABASE_URL (preparación) | Cifrar respaldo y simular restore desechable | Custodia protegida de recovery, su activación es exclusiva del DUEÑO | DUEÑO / recuperación | Sustituir credenciales, comprobar restore autorizado, retirar antiguas | Retirar accesos conservando obligaciones de retención |
| Credenciales S3-compatible (preparación) | Almacenamiento offsite de respaldo cifrado | Cuenta de proveedor aún no provisionada y almacén seguro de recovery | DUEÑO / recuperación | Sustituir identidad de servicio de privilegio mínimo | Revocar cuenta/credenciales según retención aprobada |
| Cuenta Hostinger y acceso SSH | Administración del hosting y despliegue | Consola Hostinger y SSH nominal con MFA | DUEÑO | Revisar accesos, MFA y llaves en cambios de operador | Eliminar usuario, llaves y sesiones al perder autorización |
| Cuenta GitHub y administradores GitHub Actions | Control de repositorio, CI, Secrets, permisos | Configuración de GitHub, con MFA y mínimo privilegio | DUEÑO | Auditar colaboradores, permisos y tokens externos si existen | Retirar colaboradores y revocar tokens/permisos |
| Cuenta de correo y Sentry | Administración de integraciones opcionales | Consolas de proveedor con MFA tras decisión humana | DUEÑO | Rotar credenciales de servicio y revisar miembros | Desconectar integración y retirar miembros autorizados |

## Procedimiento propuesto (no ejecutar sin autorización)

1. **Inventariar:** contrastar nombre, ubicación, responsable y estado pendiente/configurado. Nunca copiar valores a Issues, PR, logs, artifacts, capturas o chats. Designar responsables y suplentes.
2. **Guardar:** usar GitHub Actions Secrets solo para jobs y almacén seguro del runtime/proveedor para operación. MFA y mínimo privilegio. Nunca Git ni correo.
3. **Rotar:** obtener autorización del DUEÑO, definir ventana e impacto, preparar reversión, emitir credencial por canal seguro, actualizar consumidores autorizados, verificar señales sin PII, retirar credencial anterior y registrar resultado sin datos.
4. **Revocar:** al perder un operador autorización o ante exposición, DUEÑO ordena desactivar credenciales, sesiones, tokens y accesos, comprobar dependencias y registrar resultado sin valores.
5. **Verificar:** QA exige evidencias PASS/FAIL/UNKNOWN sin secretos, identidad del sistema, SHA exacto y run. UNKNOWN no significa habilitado ni go-live.

## Evidencia requerida para salida

- Registro **sin valores** por integración: nombre, estado, custodio funcional, fecha y autorización del DUEÑO; ninguna contraseña, correo personal, dirección, backup o token.
- Confirmación autorizada de MFA y privilegio mínimo; checklist de rotación, revocación y rollback por servicio.
- Referencias a ejecuciones con resultado PASS/FAIL/UNKNOWN y SHA, sin copiar cuerpos HTTP ni material sensible.
- Tests/test_salida_secretos_accesos.py y CI **Validar** en success sobre HEAD exacto, separados de cualquier observación de producción. No implican VALIDATED_IN_PRODUCTION.

## Puertas y decisiones exclusivas del dueño

- **PENDIENTE DE DECISIÓN DEL DUEÑO:** proveedor de correo SMTP, cuentas, contratos y gastos; no contratar ni enviar correo real.
- **PENDIENTE DE DECISIÓN DEL DUEÑO:** alta de Sentry, datos transmitidos y aprobación legal; no activar DSN.
- **PENDIENTE DE DECISIÓN DEL DUEÑO:** Hostinger, usuarios SSH, permisos GitHub, DNS/TLS y asignación nominal de custodios; no realizar rotación ni revocación real.
- **PENDIENTE DE DECISIÓN DEL DUEÑO:** recovery offsite, proveedor S3-compatible, costos y restore real; sin provisionamiento.
- **PENDIENTE DE DECISIÓN DEL DUEÑO:** identidad sintética, CONDOR_AUTH_SMOKE_ENABLED, datos reales, go-live y gates #389, #616 y #617.

**Fuera de alcance:** secretos o cuentas reales, gasto, hosting, despliegues, datos de clientes, migraciones, políticas jurídicas y activación live.

**Reversión:** revertir el squash documental. No se hicieron cambios operativos.
