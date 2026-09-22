# Condor App — Snapshot operativo · candidato V 0.1.14

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** endurecer la privacidad de las respuestas administrativas y APIs sin degradar el caching legítimo de superficies públicas.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.13 ·
  <strong>Candidato:</strong> V 0.1.14 ·
  <strong>Producción comprobada:</strong> V 0.1.4
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.13 EN MAIN** | SHA `00d3c8fde5b5d2c71c51975ddfb9a994f20292ec` |
| Candidato actual | 🚧 **V 0.1.14 EN VALIDACIÓN** | Issue #150 / PR #151 · [consultar SHA exacto del HEAD](https://github.com/pl0n3r/Condor/commits/trabajo/issue-150) |
| Respuestas privadas | ✅ **NO-STORE FORZADO** | Admin, APIs, diagnósticos y activación |
| Superficies públicas | ✅ **SIN CAMBIO DE POLÍTICA** | storefront, `/health` y prefijos similares no se capturan |
| Producción V 0.1.14 | ⏳ **NO VALIDADA** | merge, transición y smoke real siguen separados |

## Qué incorpora V 0.1.14

- Regresión explícita para `Cache-Control: private, no-store` en rutas administrativas y APIs sensibles.
- Verificación de eliminación de `Surrogate-Control` y `Expires` contradictorios.
- Cobertura de respuestas 200, 403 y 500 para evitar fugas por caché compartida.
- Preservación de `Referrer-Policy: no-referrer` cuando un controlador ya exige una política más estricta.
- Verificación de que storefronts públicos, `/health` y prefijos similares mantienen su política pública.
- Sin migraciones, cambios de roles ni mutaciones de datos.

## Qué sigue

- Catálogo Producto + Variante: Issue #131 / PR #152, candidato V 0.1.15.
- Activación del primer dominio real solo mediante transición operativa autorizada y verificable.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** CI verde o merge prueban código, no producción. Solo deploy observado y smoke real permiten declarar **VALIDADO EN PRODUCCIÓN**.
