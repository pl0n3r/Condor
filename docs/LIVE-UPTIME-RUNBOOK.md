# Live readiness — uptime externo y alertas al owner

Este runbook cubre únicamente la preparación de observabilidad externa de Condor durante `construccion`. No autoriza go-live, no cambia `CONDOR_PRODUCTION_STAGE` y no habilita datos reales.

## Fuente y frecuencia

`.github/workflows/external-uptime.yml` corre desde GitHub Actions, fuera del runtime Hostinger, cuatro veces por hora y también permite `workflow_dispatch`.

Cada run toma dos muestras separadas por 20 segundos. Una sola muestra no saludable no abre incidente.

El probe hace únicamente GET públicos a:

- `https://www.condorapp.com.co/health`
- `https://www.condorapp.com.co/`

No usa SSH, credenciales productivas ni secretos del repositorio. El cliente no sigue redirects fuera del origen fijo y aplica timeout y límites de lectura.

## Estados

- `HEALTHY`: `/health` responde HTTP 200 con JSON canónico (`status=ok`, SemVer, SHA de 40 hex y `schema_up_to_date=true`) y la home responde HTTP 200 HTML.
- `DEGRADED`: hay una respuesta concluyente del servidor, pero el status HTTP, content type o contrato público no es sano.
- `UNKNOWN`: timeout, TLS/red o evidencia insuficiente/stale impiden afirmar salud.
- `TRANSIENT`: las dos muestras del mismo run no confirman una condición persistente; no abre ni cierra alerta.

La evidencia con más de 20 minutos se considera stale y se degrada a `UNKNOWN`. La ausencia de un run reciente en GitHub Actions debe tratarse operativamente como `UNKNOWN`, no como `HEALTHY`.

## Alerta y deduplicación

Dos muestras consecutivas no-`HEALTHY` producen una única hoja con marker:

`<!-- condor-external-uptime-alert:v1 -->`

La hoja se crea como `tipo: incidente`, `prioridad: alta`, `estado: bloqueado`, con roles infraestructura/SRE/seguridad y asignada al owner del repositorio.

Si ya existe una hoja abierta con el marker, no se crea otra. Si el estado agregado cambia entre `DEGRADED` y `UNKNOWN`, se actualiza la misma hoja. Si por corrupción aparecieran varias hojas abiertas con el marker, el workflow falla cerrado y no elige una arbitrariamente.

La evidencia persistida se limita a estado, timestamp UTC, endpoint lógico, clase de fallo y código HTTP cuando existe. Nunca se persiste el body remoto, cookies, headers, tokens, SQL, PII ni secretos.

## Recuperación

Dos muestras consecutivas `HEALTHY` cierran la hoja abierta y dejan un comentario de recuperación. Si no existe una hoja abierta, el run saludable no muta GitHub.

Cerrar la alerta no significa que Condor esté autorizado para live; #389 conserva la puerta humana.

## Evidencia para #389

Para considerar verificada la capacidad técnica de este leaf se requiere:

1. CI/aceptación del PR en verde.
2. Al menos un run real de `External uptime Condor` sobre `main` con evidencia fresca.
3. La ruta de fallo/dedupe/recovery cubierta por los tests contractuales.

No se provoca una caída real de producción para probar la alerta. Hasta que exista un incidente real o un ejercicio explícitamente autorizado, #389 debe distinguir “mecanismo implementado y observado saludable” de “incidente real ejercitado”.

## Frescura del propio monitor (Condor #643, preparación offline)

La frescura de **la ejecución del monitor** es distinta de la salud de **Condor**. Si GitHub Actions no inicia un run, el workflow no puede darse cuenta de su propio silencio. Los schedules pueden retrasarse u omitirse; no se debe interpretar el último `success` como salud actual.

`scripts/external_uptime_run_freshness.py::project_run_freshness(runs, now, max_age_seconds=1800)` recibe **únicamente** metadatos de ejecuciones suministrados por un observador externo autorizado. No consulta GitHub ni Condor, no envía alertas y no modifica producción. Su umbral predeterminado de 30 minutos es una clasificación operativa local, no un SLA de GitHub Actions ni la antigüedad de 20 minutos de las muestras de `/health`.

- `FRESH`: ejecución terminal con identidad exacta del workflow y fecha verificable dentro del umbral, independientemente de que su conclusión sea `success`, `failure` o `cancelled`.
- `STALE`: última ejecución terminal verificable supera el umbral.
- `UNKNOWN`: entradas ausentes, identidades incompletas o incompatibles, fechas futuras, ejecución más reciente todavía en curso o metadatos inválidos. No utilizar la última muestra buena para reemplazar un estado desconocido.
- `product_health` se mantiene siempre `UNKNOWN`: un run fresco, incluso `success`, no acredita HTTP 200, ni dos probes `HEALTHY`, ni cierre de la alerta #642.

La salida permite únicamente path fijo, SHA, ID de run, instante UTC, antigüedad y razón tipada; omite datos libres del input, IP, body, headers, tokens o PII. Pruebas: `python3 -m unittest discover -s tests -p 'test_ci_external_uptime_run_freshness.py' -v`.

**Límite de implementación:** esta hoja solo crea un clasificador puro y pruebas. Para detectar silencio **mientras sucede** se requiere otro observador/scheduler autorizado que lo invoque; no se agrega en #643. La recuperación del incidente #642 continúa en manos del reconciliador existente y exige sus dos muestras canónicas `HEALTHY`. No habilitar alertas nuevas, merges, live ni cambios de WAF/hosting por inferencia.
