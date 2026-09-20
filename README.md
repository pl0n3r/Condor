# Condor App — Snapshot de deploy V 0.1.4

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo:** reservar la administración propietaria de Condor a una única cuenta y separar ese poder de todos los demás administradores.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Dominio:</strong> condorapp.com.co ·
  <strong>Runtime producción:</strong> PHP 8.5 ·
  <strong>Versión objetivo:</strong> V 0.1.4
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Producción anterior | ✅ **V 0.1.3 VALIDADA** | propietario autenticado y acceso real a `/adminpl0n3r` |
| Versión objetivo | 🚧 **V 0.1.4** | Issue #85 |
| Separación propietaria | 🚧 **EN VALIDACIÓN** | `ROLE_PLATFORM_OWNER` exclusivo para `/adminpl0n3r` |
| Otros administradores | ✅ **/admin** | la superficie administrativa normal permanece separada |
| Producción V 0.1.4 | 🚧 **PENDIENTE** | requiere merge, deploy, migración segura de la cuenta propietaria y login real |

## Qué hace V 0.1.4

- Introduce `ROLE_PLATFORM_OWNER` para la única cuenta propietaria.
- `/adminpl0n3r` exige exclusivamente el rol propietario.
- `/admin` permanece como acceso de los demás administradores.
- Retira el aprovisionamiento `app:super-admin:provision`.
- Añade `app:platform-owner:provision` y bloquea un segundo propietario.
- Migra de forma explícita la cuenta propietaria desde el antiguo `ROLE_SUPER_ADMIN` y elimina ese rol legado de la cuenta.
- La identidad y la contraseña reales del propietario permanecen fuera del repositorio.
- Añade pruebas negativas para usuario normal y Super Admin legado.

## Validación

- Head V 0.1.4: 🚧 gates en curso.
- Exact-main: 🚧 pendiente del squash merge.
- Deploy Hostinger: 🚧 pendiente.
- Aprovisionamiento/migración de la cuenta propietaria: 🚧 pendiente de producción.
- Login real en `/adminpl0n3r`: 🚧 pendiente de producción.

## Fuentes de verdad

- [AGENTES.md](AGENTES.md) — protocolo operativo.
- [ESPECIFICACIONES.md](ESPECIFICACIONES.md) — decisiones durables de producto, autorización y arquitectura.
- [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) — único Roadmap canónico, histórico y cronológico.
- [Issue #85](https://github.com/pl0n3r/Condor/issues/85) — separación entre propietario y administradores.
