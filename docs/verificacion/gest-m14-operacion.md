# Condor #1590 · M14-S05 copias y M14-S07 continuidad

**Estado: PREPARADO OFFLINE. S05 y S07: NO_VERIFICABLES en operación real.**
Una copia guardada en GitHub, un workflow verde o un test sintético NO acreditan una restauración real ni la continuidad del servicio. En construcción se ensaya con datos inventados; no se usa hosting, producción ni servicios externos.

## Ensayo offline reproducible

Se reutilizan scripts/backup-persistent-files.py y tests/test_backup_persistent_files.py. El script requiere CONDOR_RECOVERY_SANDBOX_ROOT y un directorio descartable marcado con .condor-recovery-synthetic-only (contenido exacto synthetic-only seguido de salto de línea). Solo procesa ficheros del sandbox y restaura en un destino inexistente. No accede a hosting ni backups remotos.

Desde la raíz del checkout aislado:

    python3 -m unittest discover -s tests -p 'test_m14_recovery_evidence.py' -v
    python3 -m unittest discover -s tests -p 'test_backup_persistent_files.py' -v

Las pruebas generan y eliminan su propio directorio temporal. No configurar origen de clientes ni copiar datos reales. El recibo es descartable y distingue restauración de archivos sintéticos de recuperación del producto.

## Contrato de recibo no secreto

Cada ejecución identifica un único intento, con:
- version=1 y mode=SIMULADO o OBSERVACION_AUTORIZADA; no son intercambiables.
- head_sha: 40 hex del HEAD exacto observado, no SHA de merge; observed_at_utc: fecha con zona UTC; environment: destino y tipo de entorno.
- authority y window: aprobación explícita del dueño, operador y ventana exacta en operación real; null en simulación.
- backup: identificador no secreto, fecha, hash SHA-256, ubicación protegida y conteo; jamás tokens, URLs firmadas ni PII.
- restore: estado, hash del backup usado, destino aislado, comprobación de bytes, número de archivos y permisos sin acceso público.
- continuity: secuencia de interrupción/recuperación, comprobación de servicio y RTO/RPO realmente medidos; ausentes en simulación.
- result, s05 y s07: veredictos con causas; NO_VERIFICABLE_REAL obligatorio sin evidencia operativa verificable.

tests/test_m14_recovery_evidence.py valida el contrato SIMULADO: rechaza SHA distinto o truncado, fechas sin UTC, campos faltantes, hashes distintos, ausencia de integridad, permisos abiertos, autoridad inventada, RTO/RPO o GREEN/VERIFICADO falsos. Además ejecuta el script real en un sandbox con ficheros de ejemplo, comprueba hashes, contenido y modo privado. No interpreta esto como una recuperación HTTP/DB. Los tests existentes del script ya cubren symlink, traversal, archivo corrupto y destino ocupado.

## Autoridad operativa: observación real pendiente (NO AUTORIZADA por este PR)

1. Obtener aprobación explícita del dueño para entorno, ventana y operador, y comprobar puertas y fase vigentes. Sin ello, STOP.
2. Confirmar SHA desplegado, salud/smoke inicial, permisos mínimos y un backup previo íntegro. Backup en GitHub no prueba restauración.
3. Operar únicamente en destino aislado y autorizado: nunca sobrescribir producción, borrar datos ni ejecutar SQL destructivo por inferencia.
4. Conservar recibo no secreto de job/artefacto, digest, inicio/fin UTC, HEAD, exit status, tamaño/hash, ACL, resultado y evidencias con redacción. Si la copia, restore o integridad fallan: STOP / NO_VERIFICABLE.
5. Para S07, registrar caída controlada, recuperación, comprobación funcional HTTP y DB, punto del último dato recuperado, RTO/RPO observados, rollback y actor. Restore de ficheros no demuestra recuperación de DB ni continuidad HTTP.
6. Un responsable autorizado concilia evidencia, identidad, permisos y ventana antes de cambiar el estado S05/S07. CI verde nunca sustituye esa observación.

**Fallo cerrado:** ausencia de autorización, HEAD exacto, fecha, backup previo, hash, controles de acceso, destino seguro, verificación DB/HTTP, restore o mediciones mantiene NO_VERIFICABLE. Secretos y PII nunca se almacenan en issues, PRs ni logs.

## Aceptación

- AC-01: hay prueba reproducible de ficheros sintéticos; S05 y S07 reales siguen NO_VERIFICABLES hasta observación autorizada de hosting, DB y HTTP. Este PR prepara la evidencia; no afirma AC-01 operativo completo.
- AC-02: sandbox marcado, fixture inventado, permisos privados, negativos y sin operaciones reales.
- AC-03: CI Factory v1 / Validar terminal success en el SHA exacto del PR antes de permitir merge. CI no prueba continuidad real.

Riesgo y reversión: solamente documentación y tests offline; revert del squash. No cambia runtime, secretos, datos reales, infraestructura ni go-live.
