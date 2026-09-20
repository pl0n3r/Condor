# Condor App — Snapshot de deploy V 0.1.2

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo:** alinear Condor definitivamente con PHP 8.5 y endurecer el bootstrap/runtime después de resolver el incidente de producción de V 0.1.1.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Dominio:</strong> condorapp.com.co ·
  <strong>Runtime producción:</strong> PHP 8.5 ·
  <strong>Versión objetivo:</strong> V 0.1.2
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Versión objetivo | 🚧 **V 0.1.2** | Issue #78 / PR #79 |
| Versión desplegada | ✅ **V 0.1.1** | Hostinger recibió `main`; el home volvió a cargar al corregir el runtime |
| Producción V 0.1.1 | ✅ **HOME FUNCIONAL** | validación visual real del propietario con Hostinger en PHP 8.5 |
| Runtime Hostinger | ✅ **PHP 8.5** | web funcional y CLI disponible como PHP 8.5.6 |
| Base de datos producción | ✅ **INICIALIZADA** | migración `Version20260920023000` ejecutada; `Executed: 1`, `New: 0` |
| Main base de PR #79 | ✅ | `52ac97fbfb4565741ee6353de06b754cedec99f9` |
| Deploy V 0.1.2 | 🚧 **PENDIENTE** | requiere squash merge + exact-main + observación Hostinger |

## Qué hace V 0.1.2

- PHP 8.5 queda alineado entre producción, CI y el requisito de Composer.
- Doctrine mantiene `enable_native_lazy_objects: true` sobre PHP 8.5.
- `APP_SECRET` solo se persiste en `var/runtime` con permisos restrictivos y sin fallback en directorios temporales compartidos.
- La creación concurrente del secreto reintenta la lectura para mantener un único valor entre workers.
- Si Symfony no puede iniciar, el front controller devuelve una página segura sin exponer detalles internos.
- `public/runtime-check.php` diagnostica versión, compatibilidad PHP, autoload y almacenamiento runtime sin depender del kernel.
- La versión se sincroniza en `config/version.php`, `package.json` y `package-lock.json`.

## Archivos de esta entrega

- `.github/workflows/ci.yml`
- `AGENTES.md`
- `README.md`
- `composer.json`
- `composer.lock`
- `config/packages/doctrine.yaml`
- `config/version.php`
- `package.json`
- `package-lock.json`
- `public/index.php`
- `public/runtime-check.php`
- `src/Shared/Runtime/RuntimeEnvironment.php`
- `tests/php/Shared/Runtime/RuntimeCheckTest.php`
- `tests/php/Shared/Runtime/RuntimeEnvironmentTest.php`

## Validación

- Head previo `ec715c7`: ✅ CI completo en PHP 8.5, incluyendo integración MariaDB y Playwright Chromium.
- SonarQube Cloud sobre `ec715c7`: ✅ Quality Gate passed, 0 issues nuevos y 0 hotspots.
- CodeRabbit: ✅ los dos findings válidos de seguridad/concurrencia quedaron corregidos en `4b3c11d`.
- Composer: 🚧 el head final revalida el requisito explícito `php: ^8.5` y la frescura del lockfile.
- Exact-main: 🚧 pendiente del squash merge.
- Deploy Hostinger V 0.1.2: 🚧 pendiente.
- Producción completa V 0.1.2: 🚧 pendiente de `/runtime-check.php`, `/`, `/app.css`, `/admin/login` y `/health`.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **AHORA** | Revalidar PR #79 con Composer/CI/Sonar/CodeRabbit sobre el head final |
| **SIGUE** | Squash merge y validar el SHA exacto de `main` |
| **DESPUÉS** | Observar deploy V 0.1.2 y smoke de rutas públicas/administrativas |
| **PRÓXIMO** | Implementar la superadministración global separada del RBAC de cada tenant |

## Fuentes de verdad

- [AGENTES.md](AGENTES.md) — protocolo operativo.
- [ESPECIFICACIONES.md](ESPECIFICACIONES.md) — decisiones durables.
- [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) — plan acumulativo e hitos macro de avance.
- [GLOSARIO.md](GLOSARIO.md) — términos técnicos en lenguaje de negocio.
