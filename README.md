# Condor App — Snapshot operativo · candidato V 0.1.17

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** incorporar observabilidad request-level útil y segura en el hosting actual, sin introducir daemons ni servicios externos y sin registrar paths potencialmente sensibles.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.16 · main `41ba28fd` ·
  <strong>Candidato:</strong> V 0.1.17 ·
  <strong>Producción:</strong> V 0.1.16 aún NO_OBSERVADO en la primera comprobación post-merge
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.16 / EXACT-MAIN** | SHA `41ba28fd1f6d4d3bb2f729635e23eb8943116396`; observación de release verificable |
| Candidato actual | 🚧 **V 0.1.17 EN VALIDACIÓN** | Issue #163 / PR #164 |
| Métricas request-level | ✅ **IMPLEMENTADAS EN CANDIDATO** | duración, status, memoria pico, p50/p95 y tasa 5xx |
| Privacidad de rutas | ✅ **FAIL-SAFE** | solo se persiste `_route`; sin ruta se usa `(sin_ruta)`, nunca `getPathInfo()` |
| Concurrencia del log | ✅ **BLOQUEO ATÓMICO** | append + retención bajo `LOCK_EX`; lecturas bajo `LOCK_SH` |
| Retención | ✅ **ACOTADA** | máximo 500 entradas JSON-lines en `var/log/request_metrics.log` |
| Producción V 0.1.16 | ⏳ **NO_OBSERVADO** | el smoke automático post-merge aún no vio V 0.1.16/SHA `41ba28fd` en producción |

## Qué incorpora V 0.1.17

- Registro request-level compatible con shared hosting y sin procesos residentes.
- Escritura defensiva: la observabilidad nunca debe romper el request real.
- Bloqueo único para append y rotación, evitando pérdida de entradas concurrentes.
- Lecturas compartidas con `flock(LOCK_SH)`.
- Panel de Diagnósticos con p50, p95, tasa de error 5xx y memoria pico promedio.
- Estado vacío explícito cuando todavía no hay solicitudes medidas.
- Descripción honesta del almacenamiento: el archivo agrega procesos que comparten el despliegue y persiste entre recargas de PHP-FPM mientras exista.
- Minimización de PII: los requests sin nombre de ruta usan un marcador fijo y no almacenan el path externo.
- Regresiones para percentiles, orden reciente, retención, fallo de escritura y privacidad de rutas.

## Qué sigue

- Cerrar CI, SonarQube y CodeRabbit sobre el SHA exacto final de V 0.1.17.
- Integrar serialmente solo si el candidato queda verde, sin findings válidos y sin colisiones.
- Mantener separadas la validación de código y la observación real de producción.
- Después sincronizar V 0.1.18+ contra el `main` resultante, sin reabrir frentes duplicados.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** CI verde o merge prueban código, no producción. La observación productiva sigue siendo un estado separado y verificable.