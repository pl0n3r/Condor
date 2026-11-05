# Condor #1492 — simulacro sintético de rollback y parada (v1)

**Ámbito:** ejercicio autorizado exclusivamente en CI/runner efímero. No autoriza go-live, Hostinger, rollback productivo, shutdown real, modificación de tráfico, backups reales, bases de datos, tenants ni datos personales.

## Ejecución reproducible y evidencias

El comando `python3 scripts/simular_reversion_apagado.py` ejecuta el ensayo sin flags que habiliten red remota: crea dos releases ficticios en `TemporaryDirectory`, un marcador `.previous` y un symlink `current`. Reutiliza el cuerpo shell **real** de `ops/factory/adapter.py rollback`, pero reemplaza el transporte SSH por una invocación de `sh -c` sobre el propio directorio temporal. Comprueba la identidad SHA, la nueva resolución de `current` y que un `.previous` externo o la ausencia de `.release-sha` abortan sin modificar el symlink.

Un servidor HTTP ficticio escucha **solo** en `127.0.0.1` (loopback). Con el tenant A aislado, `GET /tenant/a/view` y `POST /tenant/a/write` devuelven 503 y `Retry-After: 60` sin registrar escrituras; tenant B mantiene lectura y escritura. Tras retirar la contención ficticia A recupera servicio. **La frontera HTTP ensayada no existe aún como control servidor de Condor; no acredita shutdown productivo ni aislamiento efectivo de la aplicación real.**

Los contratos ejecutables `python3 -m unittest tests.test_ci_salida_reversion_simulacro -v` comprueban los cinco criterios. El job de CI `Probar herramientas internas del CI` descubre `tests/test_ci_salida_reversion_simulacro.py` y publica una línea `CONDOR_1492_SYNTHETIC_EVIDENCE=` con JSON minimizado, tiempos **observados** en milisegundos, timestamps UTC, SHA ficticios, estado de cada aserción y resultado. Asociar esa salida al URL de run + SHA del PR; no inventar RPO, RTO ni duración productiva. Guardar evidencias técnicas en logs del run sin correo, token, DSN ni ID de empresa real.

## Responsables y comunicación

| Rol | Ensayo | Activación real |
| --- | --- | --- |
| OWNER / comandante | **DEL_DUEÑO:** autorización pendiente para Hostinger, alcance y publicación externa | No inferir aprobación de CI/PR |
| SRE / infraestructura | Operador técnico del escenario sintético; ejecuta y mide rollback local | Debe obtener autorización específica y revisar release anterior/backup/SSH |
| QA / seguridad | Comprueba `503`, no escritura, otro tenant y recuperación sobre fixture | Debe validar API backend/colas/sesiones reales bajo puerta autorizada |
| Comunicaciones | Prepara borrador interno/externo, sin enviarlo | Canal, destinatario, texto definitivo y envíos son DEL_DUEÑO |

**Borrador interno no enviado:** «Ensayo #1492: contención y rollback sintéticos completados; sin efectos en producción. Se investigan brechas de control real».

**Borrador externo no enviado:** «Estamos evaluando un evento de disponibilidad; comunicaremos novedades por el canal oficial aprobado».

## Acciones correctivas antes del go-live

1. Implementar control servidor auténtico por tenant y global que impida escrituras, trabajos en cola y sesiones según el alcance aprobado; ensayarlo con fixtures independientes.
2. Verificar release previo, compatibilidad de esquema y mecanismo Hostinger **solo en ventana expresamente autorizada**, con prueba de restauración y observador exact-SHA independiente.
3. Designar SRE, QA, responsable de incidentes, suplencia, canal real y procedimientos de autorización/comunicación aprobados por el dueño.

**Estado: NO_VERIFICADO en producción.** La salida JSON `PASSED_SYNTHETIC_ONLY` demuestra solamente un simulacro en sandbox. Esta evidencia **no autoriza go-live**, no reemplaza la revisión de seguridad, ni las decisiones de release, backup o operación real. Referencia normativa: [runbook principal](salida-reversion-apagado.md), [recuperación](recovery-production.md) y [salida a producción](lista-salida-produccion.md).
