# Condor · reversión de despliegue y apagado de emergencia

**Estado:** runbook de preparación en fase `construccion`. Fuente: Condor #982, checklist [#980](lista-salida-produccion.md), `AGENTES.md`, [migraciones](LIVE-MIGRATION-POLICY.md) y [recuperación](recovery-production.md). **No autoriza go-live, despliegue, mantenimiento real, acceso a Hostinger, gastos, datos reales ni cambios de tenant.** No es evidencia de un simulacro ejecutado.

## Propósito, alcance y límites

Definir quién decide y cómo responder ante un despliegue defectuoso, una migración incompatible, una caída general o una emergencia de **un tenant**. Distinguir **reversión de código** (cambiar el release activo), **forward-fix de esquema** (migración aditiva nueva), **restauración** (recuperación de datos desde respaldo autorizado) y **apagado** (cortar acceso/escrituras). Son operaciones distintas: restaurar un backup no es revertir un commit, y volver al PHP anterior **no** deshace automáticamente una migración.

El adaptador existente `ops/factory/adapter.py` implementa `deploy` y `rollback` para releases versionados de Hostinger. El workflow `.github/workflows/deploy-factory.yml` está inactivo salvo habilitación explícita de `FACTORY_DEPLOY_ENABLED`; su presencia no acredita que el transport SSH, la ruta release ni el rollback estén configurados u operativos. La aplicación es multiempresa: esconder una vista de React **no** revoca sesiones ni impide escrituras al backend.

## Responsables y autoridad

| Responsable | Función | Límite |
| --- | --- | --- |
| Dueño / administrador del servicio | Declara P0/P1, autoriza salida a live, apagado global o por tenant, mantenimiento real, ejecución remota, restauración/destrucción, gastos, DNS y aviso externo. | **DEL_DUEÑO:** no se infiere consentimiento del CI o de este documento. |
| Ingeniero de guardia / infraestructura | Observa health y releases, reúne evidencias, prepara plan reversible, ejecuta **solo tras autorización aplicable** y registra hora/resultado. | No lee/publica claves ni cambia permisos por su cuenta. |
| Backend y base de datos | Confirma compatibilidad de esquema, decide propuesta de forward-fix y ensaya restauración en DB descartable. | No hace `down` destructivo ni restaura sobre DB productiva sin permiso separado. |
| QA / seguridad | Contrasta aislamiento multiempresa, controles del servidor, smoke, rollback/forward-fix y alertas. | Un smoke omitido = **NO_VERIFICADO**, no GREEN. |
| Responsable de comunicaciones | Prepara aviso interno y texto externo aprobado, seguimiento de incidente y cierre. | **DEL_DUEÑO:** canal, destinatarios y publicación externa son decisiones humanas. |

## 0. Activación y evidencia inicial (solo lectura)

1. Abrir ticket/incidente con ID, hora UTC, severidad **P0** (aislamiento de tenant, datos o indisponibilidad total) o **P1** (funcionalidad degradada), superficie afectada, release esperado (versión + SHA), última revisión de `main` y responsable. No escribir contenido de clientes en GitHub.
2. Reunir GET públicos de `/health` y `/`, estado de `schema_up_to_date`, [monitor externo](LIVE-UPTIME-RUNBOOK.md), checks de release/deploy y último aviso de post-deploy. Validar fuente, frescura, SHA y alcance: timeout/alerta UNKNOWN no es éxito ni confirmación de caída.
3. Identificar si hubo escritura de datos o migración desde el último release bueno; documentar release previo, disponibilidad de respaldo **verificado** y reversibilidad del código. Si falta evidencia, establecer `UNKNOWN` y **no** ejecutar rollback a ciegas.
4. Suspender nuevas promociones/redeploys **mediante un mecanismo autorizado y realmente disponible**. El interruptor Factory #767 es de coordinación de agentes, **no** un interruptor de tráfico de Condor. No usarlo como sustituto de un control de aplicación.
5. Elegir una vía abajo, fijar responsable de ejecución y de verificación, hora de inicio, criterio de aborto y canal de escalamiento. Una falla de seguridad o aislamiento prevalece sobre la conveniencia de servicio.

## 1. Revertir un despliegue de código

**Precondiciones:** autorización correspondiente, release anterior existente e íntegro, SHA/version exactos, compatibilidad del esquema actual con el código anterior, backups requeridos, plan de regreso y acceso al mecanismo de releases ya configurado. En `live`, **DEL_DUEÑO**: aprobación específica antes de afectar servicio.

1. Leer metadatos de `current` y `.previous` y confirmar su procedencia en el servidor sin imprimir variables de entorno. Contrastar la versión y SHA con `/health` y el registro de despliegue. Si no existe `.previous`, no improvisar un SHA, no clonar sobre producción y escalar al dueño.
2. Si la compatibilidad es afirmativa, la operación controlada **que ya existe** es `ops/factory/rollback` (delegación al adaptador `adapter.py rollback`). Este cambia el enlace `current` al release validado en `.previous`, comprobando su `.release-sha`. **Referencia, no orden de ejecutar durante #982.** No obliga al esquema a volver atrás ni restaura datos.
3. Dejar evidencia no sensible: operador/autorización, instante UTC, SHA anterior y nuevo, herramienta ejecutada, exit status, ventana y cualquier fallback. Si SSH, enlace o permisos fallan, parar, mantener el estado seguro y escalar; jamás deshabilitar validaciones de enlace/symlink.
4. Volver a comprobar `/health` HTTP 200, `status=ok`, versión/SHA esperados, `schema_up_to_date=true`, home HTTP 200 y smoke autenticado **solo cuando haya fixture sintético y puerta autorizada** según [smoke](LIVE-AUTH-SMOKE-RUNBOOK.md). Confirmar que no hay fuga entre tenants. Si no hay smoke ejecutable, registrar `NO_VERIFICADO` y no cerrar.
5. Si rollback empeora el estado, no alternar releases en bucle: detener nuevas promociones, preservar evidencia y pasar al plan de contención/forward-fix con otra decisión operativa.

## 2. Migraciones aditivas, datos y restauración

- D-054 y [política de migraciones](LIVE-MIGRATION-POLICY.md): una migración `additive` requiere dry-run, allowlist, respaldo previo exitoso, comprobaciones de servicio/esquema/smoke y puerta humana cuando aplique. `destructive` o `unknown` **fallan cerrados**. En fase `live`, `CONDOR_AUTO_MIGRATE=0` o error de preflight, nunca ejecutar automáticamente.
- Tras migración ya aplicada, priorizar **forward-fix versionado** y compatible con datos en lugar de revertir el esquema. Una reversión de código se permite solo si el release anterior interpreta el esquema actual correctamente; documentar dependencia de columnas/tablas añadidas.
- Si hay pérdida o corrupción, plantear **restore separado**, nunca implícito en `rollback`. Exigir backup con checksum y procedencia, almacenamiento externo y retención verificados, autorización específica para cualquier escritura destructiva y ventana acordada. El [simulacro](recovery-production.md) y `.github/workflows/recovery-production.yml` usan backup cifrado, offsite inmutable y restauración **exclusivamente en base descartable** con verificaciones; **no constituyen una orden ni permiso de restaurar producción**.
- El script `scripts/verify-backup-restore.sh` limita nombres de DB descartable, centinelas sintéticos y exige permiso explícito para recrearla. Un ejercicio que no tenga conexión o credenciales autorizadas queda **NO_VERIFICADO**, no aprobado. Registrar RPO y RTO observados solo con timestamps auténticos y resultado real, nunca estimados.

## 3. Apagado de emergencia y modo mantenimiento

**Disparadores:** aislamiento multiempresa comprometido, datos expuestos, uso indebido de credenciales, corrupción o escrituras inseguras, o indisponibilidad crítica confirmada. Distinguir **apagado global** de **tenant afectado**; no impactar otras empresas si es posible aislarlo con controles verificados.

1. **Decisión DEL_DUEÑO:** definir alcance global/tenant, duración, motivo, ejecutor, canal de comunicación y procedimiento de reactivación. No se asume que Condor tenga una bandera de mantenimiento por tenant o interruptor en el backoffice: **este documento no implementa ni demuestra esos controles**.
2. Diseñar contención en la **frontera del servidor**: impedir operaciones de escritura y accesos a rutas afectadas, incluidas API, jobs, sesiones y endpoints móviles, no solo ocultar botones. Cuando exista modo mantenimiento implementado y probado, preferir respuesta HTTP `503 Service Unavailable` con `Retry-After` razonable en superficies afectadas, contenido público sin datos sensibles y excepción de health operativo según el runbook correspondiente. **No inventar ni activar una ruta o variable de mantenimiento que no consta en el repo.**
3. **Tenant específico:** resolver identidad de tenant por validación del backend, nunca por un parámetro no autenticado; comprobar bloqueo de ese tenant tanto en API como UI y preservar el acceso de otros tenants mediante pruebas sintéticas de aislamiento. Si no existe una barrera granular verificable, **no afirmar apagado por tenant**: declarar insuficiencia, escalar y considerar contención global autorizada.
4. **Global:** evaluar mecanismos reales disponibles en aplicación, reverse proxy o Hostinger y solicitar su aprobación al dueño. Antes de actuar, proteger registro de órdenes/ventas y suspender tareas que escriban usando un mecanismo auditado; no alterar DNS, WAF, credenciales ni Hostinger por deducción.
5. Publicar únicamente el aviso aprobado: «Estamos realizando mantenimiento temporal; comunicaremos la recuperación por el canal oficial», sin URL privada, email, token, datos de empresa ni explicación técnica sensible. **DEL_DUEÑO:** decide texto final, destinatarios, canales, aviso legal y costos relacionados.
6. Verificar: rutas afectadas sin escrituras nuevas, sesiones revocadas **si esa operación fue explícitamente autorizada**, health y continuidad de tenants no afectados, estado de colas, y dos comprobaciones independientes de restauración. Si no hay mecanismo o evidencia, el resultado es `NO_VERIFICADO`.

## 4. Restablecimiento, comunicación y cierre

- Dueño autoriza retirar mantenimiento/reabrir tenant únicamente cuando backend, QA y seguridad demuestren salud del release/SHA, esquema actualizado, integridad y aislamiento, alertas estabilizadas y ausencia de escrituras conflictivas. No equiparar un CI verde con producción GREEN.
- Operaciones toma observaciones `/health`, home, smoke sintético según puerta, métricas recientes y estado de jobs. Registrar inicio/fin, duración observada, causa, cambio/release aplicado y riesgos que quedan.
- Comunicación envía actualización y cierre **aprobados por el dueño**. Si hubo datos personales o compromiso de seguridad, el dueño y asesoría legal evalúan obligaciones y avisos; no inventar destinatarios ni plazos regulatorios.
- Documentar lección aprendida, corrección técnica en issue independiente si existe defecto, responsable y controles pendientes. Actualizar la [lista de salida](lista-salida-produccion.md) solo cuando haya evidencia real; **#982 es cobertura documental, no prueba operativa de rollback o apagado**.

## Evidencia mínima y condiciones de aborto

| Momento | Evidencia sin datos reales | Abortar si |
| --- | --- | --- |
| Antes | ID incidente, autorización y alcance, hora UTC, SHA/version, estado health, backup verificable cuando aplique | Falta permiso, release anterior, backup requerido o compatibilidad de esquema |
| Durante | Paso realizado, herramienta aprobada, exit status, observación puntual de servicio, cambios de alcance | Se pierde aislamiento, falla backup, health o se propone acción destructiva no autorizada |
| Después | Health/SHA exactos, esquema, smoke autorizado, integridad, límites tenant, alerta y aviso aprobados | Evidencia UNKNOWN/NO_VERIFICADO, fuga inter-tenant o señales inconsistentes |

**AC-04 / gate de entrega de este runbook:** exigir `Validar` terminal `success` sobre el HEAD exacto del PR, reserva V3 y claims vigentes, calidad y seguridad revisadas; luego squash y comprobación de `main`. Ningún documento ni prueba local sustituye ese check. Este issue no ejecuta los pasos productivos.
