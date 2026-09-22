# Condor App — Snapshot operativo · candidato V 0.1.18

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** convertir las señales funcionales ya capturadas por Condor en un reporte operativo simple y verificable, sin introducir analítica externa ni exponer PII.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.17 · main `45b3c84a` ·
  <strong>Candidato:</strong> V 0.1.18
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.17 / EXACT-MAIN** | SHA `45b3c84afe3e0a1b03e2781a1ead884704569057` |
| Candidato actual | 🚧 **V 0.1.18 EN VALIDACIÓN** | Issue #165 / PR #166 |
| Reporte funcional | ✅ **IMPLEMENTADO EN CANDIDATO** | CSV privado de 30 días desde señales agregadas |
| Índice de consulta | ✅ **ADITIVO** | `(created_at, type)` para el patrón real del reporte |
| Validación de datos | ✅ **MARIA DB REAL** | prueba de integración cruza el límite de día UTC |
| Privacidad | ✅ **SIN PII NUEVA** | el reporte usa tipos de señal y conteos diarios |

## Qué incorpora V 0.1.18

- Servicio `FunctionalSignalReport` que agrega señales funcionales por día UTC y tipo.
- Exportación CSV privada para propietario de plataforma desde Diagnósticos.
- Índice `idx_functional_signal_created_type` alineado con el filtro temporal del reporte.
- Migración aditiva y reversible para el nuevo índice.
- Prueba de integración real contra MariaDB alrededor de un límite de día UTC.
- Gateway transaccional nulo explícito para despliegues sin proveedor de correo configurado.
- Diagnósticos conserva íntegramente las métricas request-level introducidas en V 0.1.17.

## Fuera de alcance

- dashboards BI externos;
- series temporales ilimitadas;
- datos personales o contenido de negocio en el reporte;
- alertas proactivas por umbral.

## Qué sigue

- Cerrar CI, SonarQube y CodeRabbit sobre el SHA exacto final de V 0.1.18.
- Integrar serialmente solo cuando el candidato quede sin findings válidos y 0 commits detrás de `main`.
- Resincronizar V 0.1.19+ contra el `main` resultante.
- Mantener separado el estado de código de la evidencia de producción.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** merge y CI verde prueban código; producción se observa y valida por separado.
