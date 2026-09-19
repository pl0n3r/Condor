# Condor App — Snapshot de desarrollo

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)

> **Panel visual del estado actual del proyecto.**  
> El progreso acumulativo vive en el [roadmap canónico — Issue #1](https://github.com/pl0n3r/Condor/issues/1).

<p align="center">
  <strong>Mercado:</strong> Colombia ·
  <strong>Idioma:</strong> es-CO ·
  <strong>Dominio:</strong> www.condorapp.com.co ·
  <strong>Versión objetivo:</strong> V 0.1.0
</p>

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Versión objetivo | 🚧 **V 0.1.0** | primera entrega en preparación |
| Versión desplegada | ⚪ **Sin deploy todavía** | no existe evidencia de una versión en producción |
| Base exacta previa | ✅ **main** | `a7d38bf6150038ff2bba3332c493e99b00cfcd65` |
| CI | ✅ **Activo** | PRs #9/#11 y CI exact-main sobre `4051563` pasaron con check agregado `Validar` |
| SonarQube Cloud | ✅ **Activo** | PR #14: Quality Gate passed, 0 issues nuevos y 0 hotspots |
| CodeRabbit | ✅ **Configurado** | `.coderabbit.yaml` activo con perfil assertive e instrucciones de revisión en español |
| Coordinación multiagente | 🚧 **En validación** | Issue #12: reserva atómica por Issue + detección de solapamientos |
| Producción | ⚪ **NO VALIDADA** | CI/merge no sustituyen despliegue ni validación real |

## Fuentes de verdad

| Fuente | Propósito |
| --- | --- |
| [AGENTES.md](AGENTES.md) | cómo se trabaja |
| [ESPECIFICACIONES.md](ESPECIFICACIONES.md) | reglas, arquitectura y decisiones durables |
| [Roadmap #1](https://github.com/pl0n3r/Condor/issues/1) | trabajo planeado, realizado, pendiente y bloqueado |
| [GLOSARIO.md](GLOSARIO.md) | explicación sencilla de términos técnicos para seguimiento de negocio |

## Flujo de entrega

```mermaid
flowchart LR
 A["Issue disponible"] --> B["/tomar"]
 B --> C["Lock + UUID de reserva"]
 C --> D["trabajo/issue-N"]
 D --> E["PR + Closes #N + Reserva UUID"]
 E --> F["CI + coordinación"]
 F --> G["Sonar / CodeRabbit"]
 G --> H["Squash merge serial"]
 H --> I["Validación exacta de main"]
 I --> J["Deploy Hostinger"]
 J --> K["Versión desplegada observada"]
 K --> L["Validación real de producción"]
```

## Qué se hizo

- Gobierno documental y roadmap establecidos.
- `AGENTES.md`, `ESPECIFICACIONES.md` y `GLOSARIO.md` separados por responsabilidad.
- Versionado de tracking obligatorio en títulos de GitHub.
- Roadmap convertido en log exclusivo de trabajo/progreso.
- Issue #8 cerrado: gobierno técnico y CI base validados en código.
- CI canónico activo con preflight, validaciones paralelas y check agregado `Validar`.
- Plantillas de Issues/PR en español activas.
- Labels en español sincronizados y milestone **Primera entrega (V 0.1.0)** creado.
- CodeRabbit configurado con perfil assertive e instrucciones generales de revisión en español.
- Núcleo de coordinación multiagente preparado: reserva atómica por Issue, UUID por sesión, marcadores confiables, estados de cola y detección de archivos solapados.

## Archivos de la entrega actual

- `.coderabbit.yaml`
- `.github/ISSUE_TEMPLATE/config.yml`
- `.github/ISSUE_TEMPLATE/error.yml`
- `.github/ISSUE_TEMPLATE/mejora.yml`
- `.github/ISSUE_TEMPLATE/tarea.yml`
- `.github/pull_request_template.md`
- `.github/labels.json`
- `.github/workflows/ci.yml`
- `.github/workflows/sincronizar-gobierno.yml`
- `scripts/validar_documentacion.py`
- `README.md`

## Validación

- PRs #9/#11 validaron el CI inicial; el SHA exacto `4051563` terminó con CI y gobierno en verde.
- El título del PR es rechazado automáticamente si no termina con `(V X.Y.Z)`.
- Los enlaces relativos y archivos documentales canónicos tienen validación automática.
- El workflow de gobierno sincronizó labels y creó el milestone #1 `Primera entrega (V 0.1.0)`.
- SonarQube Cloud está activo; el Quality Gate se revisa en cada PR cuando la integración reporta resultados.
- No existe evidencia de deploy ni validación de producción.

## Qué sigue

| Lane | Trabajo |
| --- | --- |
| **AHORA** | 🚧 Validar coordinación multiagente y reservas de trabajo, Issue #12. |
| **SIGUE** | 🚧 Protección de `main` con gates `Validar` + coordinación, Issue #10; bloqueo administrativo vigente. |
| **DESPUÉS** | 🚧 Definición funcional del producto + bootstrap técnico de aplicación. |
| **CALIDAD** | 🚧 SonarCloud + baseline de pruebas + Playwright. |
| **DEPLOY** | 🚧 Preparar Hostinger y realizar el primer deploy real **V 0.1.0**. |

## Panorama general pendiente

| Frente | Estado |
| --- | --- |
| Gobierno y trazabilidad | 🟢 Base activa; protección de `main` bloqueada en Issue #10 |
| CI base | 🟢 Activo |
| Definición del producto | ⚪ Pendiente |
| Arquitectura | ⚪ Pendiente |
| SonarQube Cloud | 🟢 Activo; ampliar integración con CI |
| Pruebas | ⚪ Pendiente baseline funcional/E2E |
| Seguridad base | ⚪ Pendiente |
| Diseño y UX | ⚪ Pendiente |
| Primer vertical slice | ⚪ Pendiente |
| Hostinger + primer deploy V 0.1.0 | ⚪ Pendiente |
