# Condor App — Snapshot de deploy V 0.1.2

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo:** eliminar el HTTP 500 de Hostinger y hacer observable cualquier fallo de bootstrap sin exponer información sensible.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Dominio:</strong> condorapp.com.co ·
  <strong>Runtime producción:</strong> PHP 8.3 ·
  <strong>Versión objetivo:</strong> V 0.1.2
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Versión objetivo | 🚧 **V 0.1.2** | Issue #78 |
| Versión desplegada | ⚠️ **V 0.1.1** | Hostinger confirmó deploy de `main` a `public_html` |
| Producción V 0.1.1 | ⛔ **HTTP 500** | navegador del propietario confirma respuesta 500 en `/` |
| Runtime Hostinger | ✅ **PHP 8.3** | confirmado por el propietario |
| Main base | ✅ | `52ac97fbfb4565741ee6353de06b754cedec99f9` |
| Producción | ⛔ **NO VALIDADA** | no cerrar hasta que el home cargue correctamente |

## Qué se hace en V 0.1.2

- `APP_SECRET` usa almacenamiento temporal seguro si `var/runtime` no es writable.
- Si Symfony no puede iniciar, se devuelve una página de error segura en vez de una respuesta vacía.
- `public/runtime-check.php` permite comprobar versión, compatibilidad PHP, autoload y almacenamiento runtime sin depender del kernel.
- CI pasa a ejecutar PHP 8.3 para reflejar el runtime real de Hostinger.
- Doctrine desactiva `enable_native_lazy_objects` porque esa función requiere PHP 8.4+ y era la causa reproducida del HTTP 500 en PHP 8.3.
- La versión se sincroniza en `config/version.php`, `package.json` y `package-lock.json`.

## Archivos de esta entrega

- `public/index.php`
- `public/runtime-check.php`
- `src/Shared/Runtime/RuntimeEnvironment.php`
- `tests/php/Shared/Runtime/RuntimeEnvironmentTest.php`
- `tests/php/Shared/Runtime/RuntimeCheckTest.php`
- `.github/workflows/ci.yml`
- `config/version.php`
- `package.json`
- `package-lock.json`
- `AGENTES.md`
- `README.md`

## Validación requerida

- PHP 8.3 + Composer + Symfony: 🚧
- PHPUnit: 🚧
- MariaDB/integración: 🚧
- Playwright Chromium: 🚧
- SonarQube Cloud: 🚧
- Exact-main: 🚧
- Deploy Hostinger V 0.1.2: 🚧
- Home real sin HTTP 500: 🚧

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **AHORA** | Validar Doctrine sobre PHP 8.3 y cerrar PR #79 |
| **SIGUE** | Squash merge y exact-main |
| **DESPUÉS** | Observar `/runtime-check.php`, `/`, `/app.css` y `/admin/login` en Hostinger |
| **P0** | No declarar producción validada hasta eliminar el HTTP 500 |

## Fuentes de verdad

- [AGENTES.md](AGENTES.md) — protocolo operativo.
- [ESPECIFICACIONES.md](ESPECIFICACIONES.md) — decisiones durables.
- [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) — plan acumulativo y bitácora cronológica.
