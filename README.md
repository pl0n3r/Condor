# Condor App — Snapshot operativo · candidato V 0.1.12

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** convertir actividad real de plataforma en señales funcionales agregadas, útiles y sin PII para operación y producto.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.11 ·
  <strong>Candidato:</strong> V 0.1.12 ·
  <strong>Producción comprobada:</strong> V 0.1.4
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.11 EN MAIN** | SHA `346ddabe46b54240326e5d6c62a956464a0ea663` |
| Candidato actual | 🚧 **V 0.1.12 EN VALIDACIÓN** | Issue #128 / PR #143 |
| Métricas funcionales | ✅ **IMPLEMENTADAS EN CANDIDATO** | tenant creado, login OK/fallido, autorización denegada y rol modificado |
| Privacidad | ✅ **MINIMIZADA** | sin correo, IP, contraseña, token ni request body |
| Producción V 0.1.12 | ⏳ **NO VALIDADA** | requiere merge, exact-main, transición y smoke real |

## Qué incorpora V 0.1.12

- Señales persistentes y agregables con catálogo cerrado.
- Registro defensivo: un fallo de telemetría no rompe el flujo de negocio.
- `tenant_created` después de transacción exitosa en onboarding y alta desde Super Admin.
- Login exitoso/fallido y autorización denegada mediante subscribers.
- Cambios de roles como señal funcional además de la auditoría de negocio.
- Agregación de 30 días disponible solo para `ROLE_PLATFORM_OWNER`.
- Panel visual con estado vacío explícito dentro de `/adminpl0n3r`.
- Pruebas de aislamiento, persistencia real y ausencia de PII.

## Qué sigue

- Storefront administrable: #130 / PR #136, a reserializar como siguiente versión después de este candidato.
- Catálogo Producto + Variante: #131.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** CI verde o merge prueban código, no producción. Solo deploy observado y smoke real permiten declarar **VALIDADO EN PRODUCCIÓN**.
