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
