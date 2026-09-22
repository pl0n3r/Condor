# Condor App — Snapshot operativo · candidato V 0.1.13

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** convertir la base multi-tenant en un storefront público administrable, seguro y preparado para dominios personalizados reales.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.12 ·
  <strong>Candidato:</strong> V 0.1.13 ·
  <strong>Producción comprobada:</strong> V 0.1.4
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.12 EN MAIN** | SHA `fa8f7e156f41155a7f99f5b48c7ec60cbd2ab948` |
| Candidato actual | 🚧 **V 0.1.13 EN VALIDACIÓN** | Issue #130 / PR #136 · [consultar SHA exacto del HEAD](https://github.com/pl0n3r/Condor/commits/trabajo/issue-130) |
| Storefront por tenant | ✅ **IMPLEMENTADO EN CANDIDATO** | perfil público + SSR real |
| Dominios personalizados | ✅ **MODELO Y RESOLUCIÓN** | solo dominios verificados resuelven |
| Producción V 0.1.13 | ⏳ **NO VALIDADA** | DNS/TLS/Hostinger requieren evidencia operativa separada |

## Qué incorpora V 0.1.13

- Identidad pública mínima editable por tenant.
- Storefront Twig/SSR con datos reales y fallback seguro.
- Resolución por slug Condor o dominio personalizado verificado.
- Host desconocido y dominio no verificado fallan cerrado.
- Canonical basado en el dominio primario efectivo.
- Administración protegida por `site.view` / `site.update` y CSRF.
- Aislamiento cross-tenant probado, incluido usuario delegado read-only.
- Estado interno de dominio visible sin simular DNS/TLS reales.
- Guía operativa para activación, smoke y recuperación en Hostinger.

## Qué sigue

- Activar el primer dominio real únicamente mediante transición operativa autorizada y verificable.
- Catálogo Producto + Variante: Issue #131.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** “verificado” en Condor no significa “activo en producción”. DNS, TLS, deploy observado y smoke real son evidencias separadas.
