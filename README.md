# Condor App — Snapshot de deploy V 0.1.3

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo:** habilitar una administración global segura de Condor, separada de los permisos internos de cada empresa.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Dominio:</strong> condorapp.com.co ·
  <strong>Runtime producción:</strong> PHP 8.5 ·
  <strong>Versión objetivo:</strong> V 0.1.3
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Versión objetivo | 🚧 **V 0.1.3** | Issue #81 / PR #82 |
| Versión desplegada confirmada | ✅ **V 0.1.1** | última evidencia productiva explícita registrada |
| V 0.1.2 | ✅ **VALIDADA EN CÓDIGO** | PR #79 fusionada y exact-main verde; observación de deploy pendiente |
| Base de datos producción | ✅ **INICIALIZADA** | migración inicial aplicada; esquema al día al cierre de V 0.1.2 |
| V 0.1.3 | 🚧 **EN VALIDACIÓN** | Super Admin global implementado y gates finales en curso |
| Producción V 0.1.3 | 🚧 **PENDIENTE** | requiere merge, deploy observado, aprovisionamiento y login real |

## Qué hace V 0.1.3

- Añade `ROLE_SUPER_ADMIN` como rol global de plataforma.
- Añade la superficie protegida `/superadmin`.
- Mantiene separado el Super Admin del RBAC y de las membresías de cada tenant.
- Redirige a un Super Admin desde `/admin` hacia la administración global.
- Añade `app:super-admin:provision` para crear o promover la cuenta inicial.
- La contraseña se toma desde `CONDOR_SUPER_ADMIN_PASSWORD` y nunca se imprime ni versiona.
- Al promover una cuenta existente, la contraseña de aprovisionamiento reemplaza la anterior antes de conceder privilegios globales.
- Añade regresiones de autorización y de rotación de contraseña.

## Archivos principales de esta entrega

- `config/packages/security.yaml`
- `config/version.php`
- `package.json`
- `package-lock.json`
- `src/Application/Identity/ProvisionSuperAdmin.php`
- `src/Console/ProvisionSuperAdminCommand.php`
- `src/Domain/Identity/Entity/User.php`
- `src/Http/Controller/AdminController.php`
- `src/Http/Controller/SuperAdminController.php`
- `templates/superadmin/index.html.twig`
- `tests/php/Application/Identity/ProvisionSuperAdminTest.php`
- `tests/php/Http/SuperAdminControllerTest.php`

## Validación

- CI previo del Super Admin: ✅.
- Finding válido de CodeRabbit sobre promoción de cuentas existentes: ✅ corregido con regresión.
- SonarQube Cloud previo: ✅ Quality Gate passed.
- Head final V 0.1.3: 🚧 revalidación en curso.
- Exact-main: 🚧 pendiente del squash merge.
- Deploy Hostinger V 0.1.3: 🚧 pendiente.
- Aprovisionamiento de la cuenta real: 🚧 pendiente de credenciales suministradas fuera del repositorio.
- Login real y acceso a `/superadmin`: 🚧 pendiente de producción.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **AHORA** | Revalidar PR #82 sobre el head final V 0.1.3 |
| **SIGUE** | Squash merge y validar el SHA exacto de `main` |
| **DESPUÉS** | Observar deploy de V 0.1.3 en Hostinger |
| **FINAL** | Aprovisionar la cuenta propietaria y validar login + `/superadmin` |

## Fuentes de verdad

- [AGENTES.md](AGENTES.md) — protocolo operativo.
- [ESPECIFICACIONES.md](ESPECIFICACIONES.md) — decisiones durables.
- [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) — único Roadmap canónico, histórico y cronológico.
- [GLOSARIO.md](GLOSARIO.md) — términos técnicos en lenguaje de negocio.
