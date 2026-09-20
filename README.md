# Condor App — Snapshot de deploy V 0.1.4

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** mantener un único propietario de plataforma y añadir diagnóstico seguro, trazable y compartible para errores de producción.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Dominio:</strong> condorapp.com.co ·
  <strong>Runtime producción:</strong> PHP 8.5 ·
  <strong>Versión objetivo:</strong> V 0.1.4
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Producción confirmada | ✅ **V 0.1.3 VALIDADA** | login real del propietario y acceso a `/adminpl0n3r` antes del endurecimiento V 0.1.4 |
| Código V 0.1.4 | ✅ **VALIDADO EN CÓDIGO** | propietario único fusionado; exact-main `f6fe2ef75e44681fde199fdf67e672312374e086` con gates en validación/verde según bloque |
| Propietario único | ✅ **IMPLEMENTADO** | `ROLE_PLATFORM_OWNER`, singleton persistente y locking transaccional |
| Administradores delegados | ✅ **SEPARADOS** | entran por `/admin`; no obtienen acceso propietario |
| Diagnóstico seguro | 🚧 **EN VALIDACIÓN** | Issue #93 / PR #94 |
| Producción V 0.1.4 | ⚠️ **NO VALIDADA** | se reportó HTTP 500 en `/adminpl0n3r`; requiere comprobar caché, migraciones y login real antes de cerrar |

## Qué incorpora V 0.1.4

- `ROLE_PLATFORM_OWNER` reservado a una única cuenta propietaria.
- `/adminpl0n3r` exclusivo del propietario; administradores ordinarios permanecen en `/admin`.
- Unicidad respaldada por base de datos y locking transaccional.
- Observador de releases con retries únicamente para fallos realmente transitorios.
- Diagnóstico estructurado de errores 5xx con error ID y request ID.
- Panel propietario en `/adminpl0n3r/diagnosticos`.
- Enlaces diagnósticos de solo lectura, sanitizados, revocables y con expiración de 30 minutos.
- Tokens compartibles generados con 256 bits de entropía y almacenados únicamente como hash.
- Retención inicial de incidentes de 14 días mediante `app:diagnostics:prune`.
- Ningún diagnóstico compartido incluye headers, cookies, request bodies, argumentos del stack, secretos, DSN ni PII innecesaria.

## Validación

- Propietario único: ✅ CI, Playwright, SonarQube y CodeRabbit; fusionado.
- Observador autocurable: ✅ PR #92 fusionado; exact-main en validación final.
- Diagnóstico seguro: 🚧 PR #94; CI/CodeRabbit/Sonar en validación.
- Deploy Hostinger V 0.1.4: 🚧 no se considera validado mientras persista el 500 reportado.
- Migraciones de producción: 🚧 se inspeccionan y aplican de forma separada al deploy de código.

## Fuentes de verdad

- [AGENTES.md](AGENTES.md) — protocolo operativo.
- [ESPECIFICACIONES.md](ESPECIFICACIONES.md) — decisiones durables, incluida D-047 para diagnóstico seguro.
- [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) — único Roadmap canónico.
- [Issue #93](https://github.com/pl0n3r/Condor/issues/93) — diagnóstico seguro y compartible.
