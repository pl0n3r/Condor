# Condor — respaldo programado y restauración comprobable (plan de salida)

**Estado:** documentación build-ahead; NO AUTORIZADO para ejecución ni go-live. Este documento no establece que existan respaldos automáticos activos, archivos respaldados ni un ensayo de restauración real satisfactorio. Fase construccion; no usar datos reales en esta hoja.

## Propósito, alcance y responsabilidades

Evitar que la pérdida de la base de datos o de archivos operativos de Condor obligue a reconstruir información sin evidencia recuperable. El alcance incluye **base de datos** MariaDB/MySQL compatible (esquema, tablas, migraciones, datos del producto) y **archivos** persistentes no regenerables (adjuntos y cargas de usuario, si llegan a existir). El código versionado y artefactos de release se recuperan del SHA y de la release oficial, y no sustituyen las copias de datos. Antes de activar el plan se inventarían las ubicaciones reales de archivos y exclusiones; no suponer que public/, var/ o Hostinger contienen automáticamente todos los datos necesarios.

| Rol | Responsabilidad y límite |
| --- | --- |
| OPERADOR/SRE designado | Revisar éxito y retraso de cada backup, conservar el manifiesto, diagnosticar fallos, preparar ensayo en infraestructura aislada y documentar sus resultados. La identidad y el turno aún no están asignados. |
| RESPONSABLE DE SEGURIDAD/PRIVACIDAD | Validar destino, cifrado, inventario de archivos, minimización y contrato del proveedor antes de mover información real. |
| DUEÑO de Condor | Decide proveedor/cuenta, gasto, retención final, tratamiento de datos reales, persona responsable, ventana de recuperación y autorización de restauración productiva. |
| ControlBot + FactoryRunner | Componentes futuros del circuito gobernado: referencia de conexión y privacidad, movimiento offsite y recibos; su presencia en código no acredita conexión activada. |

## Arquitectura comprobada y brechas

- **Base de datos:** scripts/backup-database.sh produce un respaldo lógico comprimido con cuenta limitada, umask restrictivo y fallback PDO para hosting compartido. BACKUP_DIR puede apuntar a almacenamiento privado. BACKUP_KEEP controla únicamente copias históricas de más de 30 días; NO es prueba de retención offsite o de agenda. La migración live sigue su puerta separada D-054 y docs/LIVE-MIGRATION-POLICY.md.
- **Ensayo existente:** .github/workflows/recovery-production.yml es exclusivamente workflow_dispatch **manual**. La secuencia de docs/recovery-production.md toma un respaldo, cifra con AES-256-CBC/PBKDF2 del lado cliente, calcula checksum SHA-256 del ciphertext, usa FactoryRunner para upload/verify/materialize con versionado y Object Lock COMPLIANCE, descifra y comprueba restore.
- **Restauración segura:** scripts/verify-backup-restore.sh acepta solamente una base de datos explícitamente **descartable** y un permiso de restauración destructiva limitado a esa base; comprueba tablas, migraciones y centinelas autorizados. La app local evalúa /health, smoke de / e integridad contra la base aislada, nunca contra producción.
- **Archivos persistentes:** no hay prueba de un backup programado de archivos ni de su restauración. La cobertura exige inventario firmado de rutas/orígenes, snapshot cifrado con manifest/hash por objeto, offsite inmutable, verificación de recuperación en directorio aislado y permisos/propietarios restaurables. Sin inventario y prueba, este punto permanece **PENDIENTE**.
- **ControlBot:** las variables de referencia de conexión RECOVERY_PRIMARY_CONNECTION_REF y de privacidad RECOVERY_PROVIDER_PRIVACY_REF son precondiciones; no se publican credenciales. Si la conexión ControlBot o su autorización están apagadas, el flujo falla cerrado. No seleccionar ni aprovisionar otro proveedor sin decisión humana.

## Frecuencia, retención, cifrado, RPO y RTO propuestos

| Parámetro | Propuesta de diseño; NO activada | Condición para aprobar |
| --- | --- | --- |
| Frecuencia DB | Evaluar un respaldo **cada 5 minutos** solamente si carga, capacidad y proveedor lo permiten; todo backup completo debe quedar verificado offsite antes del plazo. | DUEÑO autoriza agenda, cuota, ventana, operación y gasto; OPERADOR demuestra rendimiento sostenido. |
| Frecuencia archivos | Copia tras cambio y reconciliación periódica, alineada a la misma ventana máxima de pérdida de datos, con manifiesto y checksum. | Inventario real sin datos expuestos y ensayo de archivos desde copia aislada. |
| RPO objetivo | **900 segundos (15 minutos)**: diferencia entre instante de incidente y fecha del respaldo restaurado. | Medición real <= 900 s; un cron cada 15 min por sí solo NO garantiza RPO. |
| RTO objetivo | **3600 segundos (60 minutos)**: tiempo entre inicio y fin efectivo del restore + integridad + health + smoke. | Medición real <= 3600 s, comparada con Factory #330. |
| Retención local | Script existente protege copias recientes **30 días**; BACKUP_KEEP limita histórico adicional, no reemplaza política aprobada. | DUEÑO aprueba límites y OPERADOR demuestra espacio, recuperación y expiración. |
| Retención offsite | Almacenamiento cifrado, versionado y Object Lock COMPLIANCE; parámetro existente de 1 a 3650 días. La duración concreta sigue **SIN DECIDIR**. | DUEÑO aprueba duración/costo; recibo remoto debe demostrar lock vigente, no solo VersionId. |
| Cifrado y accesos | Cifrado cliente AES-256-CBC/PBKDF2 antes del proveedor; checksum SHA-256, mínimo privilegio y material de cifrado fuera de logs/repositorio. | SEGURIDAD verifica custodia, rotación/recuperabilidad de claves y prueba de descifrado. |

Un intervalo propuesto, un archivo de workflow, una variable de configuración o CI verde nunca significan que la programación esté activada. No se ejecutan agendas ni recuperación en esta tarea.

## Pasos de implementación futura (sujetos a puertas)

1. OPERADOR inventaría base de datos y archivos persistentes, volumen y residencia, identifica responsable y documenta exclusiones/periodos; SEGURIDAD revisa tratamiento y proveedor.
2. DUEÑO decide el proveedor, cuenta y eventual gasto, autoriza retención, cifrado/custodia y uso de datos reales; hasta entonces mantener todo **NO AUTORIZADO**.
3. Con ControlBot/FactoryRunner autorizados y el permiso operativo correspondiente, el OPERADOR configura un programador externo gobernado que reutilice el backup probado; nunca se deduce agenda de workflow_dispatch. Vigilar el SLA de cada ejecución y desactivar/alertar ante error o copia offsite retrasada.
4. En cada ciclo conservar solo evidencia técnica minimizada: identificador de ejecución y SHA, hora UTC del backup, checksum SHA-256, identidad opaca de objeto y versión inmutable, instante de verificación, estado de cifrado/Object Lock, resultado y latencia de protección. No registrar contenido, volcados SQL, rutas de clientes, DSN, correos o secretos.
5. Antes de live y luego **mensualmente** como propuesta, el OPERADOR ejecuta un **ensayo repetible** únicamente en base y directorio descartables: seleccionar copia verificada, verificar integridad offsite, materializar, descifrar en aislamiento, restaurar base, rehidratar archivos, comprobar sus hashes y ejecutar schema, centinelas sintéticos, /health y smoke.
6. Comparar por separado RPO observado = incident_at - recovery_point_at, RTO observado = completed_at - started_at, y latencia de protección = offsite_verified_at - backup_created_at. La latencia de protección **no** es RPO. Factory #330 evalúa PASSED o BREACHED y razones RPO_EXCEEDED/RTO_EXCEEDED; un simulacro incompleto no acredita recuperación.
7. Ante pérdida: OPERADOR declara incidente y preserva evidencias, verifica últimas copias, prepara plan y ofrece al DUEÑO impacto/tiempos. Restauración productiva, sustitución de datos o cambio de DNS requieren **autorización humana separada**, backup previo aplicable y ventana/reversión. Nunca ejecutar restore destructivo sobre producción en este runbook.
8. Si faltan conexión, privacidad, Object Lock, material cifrado recuperable, archivos, health, smoke, integridad o aprobación, detener y registrar **BLOQUEADO**. No declarar respaldo probado ni recuperación validada sin recibos reales de restore.

## Evidencia mínima para desbloquear salida a producción

- Contrato y permisos del proveedor, ubicación/retención aprobadas, inventario de archivos, responsable y cobertura; decisión humana registrada para todo gasto/cuenta.
- Recibos de ejecuciones programadas realmente observadas, checksum y versión inmutable offsite con Object Lock vigente, cifrado recuperable y alertas de fallos comprobadas.
- Reporte de **ensayo repetible** reciente para DB y archivos, sobre destino aislado, con integridad, centinelas, health/smoke, RPO y RTO medidos. Referencias a los runs; nunca sus datos reales.
- Si ControlBot no está conectado, si los archivos carecen de prueba, o si no existe restauración observada, el estado sigue **NO VERIFICADO**. CI solo valida la calidad de este documento.

## DECISIÓN DEL DUEÑO: puertas pendientes

- **DECISIÓN DEL DUEÑO: proveedor y cuenta.** Elegir destino offsite compatible, autorizar alta/condiciones; ninguna conexión real se crea aquí.
- **DECISIÓN DEL DUEÑO: gasto y capacidad.** Autorizar expresamente costos, volumen, intervalos y cuotas antes de programar nada.
- **DECISIÓN DEL DUEÑO: retención y privacidad.** Aprobar días de conservación, residencia, contrato, accesos, custodia/rotación de clave y datos reales; revisar D-059.
- **DECISIÓN DEL DUEÑO: agenda y responsables.** Aprobar la frecuencia operativa, designar al OPERADOR y calendario de ensayo; ControlBot apagado no concede autorización.
- **DECISIÓN DEL DUEÑO: restauración productiva y go-live.** Autorizar por separado la operación de recuperación real y la salida en vivo. El presente documento NO sustituye esa decisión.

## Verificación offline y reversión de este cambio

La prueba documental tests/test_salida_respaldo_restauracion.py verifica estructura, objetivos, límites de seguridad y permisos; la aceptación final requiere Validar en success sobre el HEAD exacto. No se invoca .github/workflows/recovery-production.yml ni se restablecen datos. Revertir este PR retira solo documentación y prueba, sin tocar producción.
