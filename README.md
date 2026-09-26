# Condor App — Snapshot operativo · GitHub CI de baja amplificación V 0.1.49

> **Candidato:** Issue #188, optimización de disparadores y observadores sobre Factory v1.

Condor continúa en **construcción**. V0.1.49 reduce llamadas y ejecuciones derivadas de GitHub Actions sin cambiar la funcionalidad del SaaS, el protocolo Factory v1 ni la política de producción.

## Alcance
- throughput CI por `schedule` horario o `workflow_dispatch`, sin artifacts duplicados por run ni reportes cuando no hay CI reciente;
- relay de Sonar solo manual, preservando el Quality Gate nativo;
- coordinación `issue_comment` exclusivamente `created`, manteniendo filtros de comandos en el job;
- observadores de deploy/release con concurrencia cancelable para evidencia reemplazada;
- reglas locales anti-polling, push agrupado y máximo 2–3 agentes simultáneos;
- pruebas de contrato deterministas en `tests/test_github_load_contract.py`.

## Seguridad y reversión
La telemetría conserva token de solo lectura y artifacts de evidencia; el relay mantiene controles de asociación check→PR. El sweep programado Factory v1 queda intacto. Ningún cambio muta producción, BD, migraciones, Hostinger o secretos. Revertir el PR recupera los disparadores anteriores, con mayor tráfico de GitHub.

## Evidencia base
- `main@c80859cf692be708f81da10d84c20096a1e15a9e` · V0.1.48; #251 / PR #252 ya fusionado; #191 quedó pausado sin merge.
- Factory v1 sweep horario: run #36143480634 SUCCESS previo a este corte.
- Merge/CI y observación de producción de V0.1.49 deben verificarse por separado; este snapshot no declara un deploy confirmado.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #188
