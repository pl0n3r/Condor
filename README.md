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
| Base exacta previa | ✅ **main** | `c0d015008f7b178a515620e8ef18135e27862282` |
| CI | ✅ **Activo** | `main` `c0d0150`: CI exact-main verde; gates paralelos + coordinación + `Validar` |
| Telemetría CI | ✅ **Activa** | run `35467077212`: 19 s wall time; artifact generado; baseline aún insuficiente con 2 muestras |
| SonarQube Cloud | ✅ **Activo** | Quality Gate + relay detallado de hallazgos hacia comentarios de PR |
| Relay Sonar | ✅ **Activo** | `main` `756ca31`: publica/actualiza un comentario único con archivo, línea, severidad y detalle de cada anotación |
| CodeRabbit | ✅ **Configurado** | `.coderabbit.yaml` activo con perfil assertive e instrucciones de revisión en español |
| Coordinación multiagente | ✅ **Activa** | Issue #18 validó toma única, rechazo de colisión y liberación; PR #20 cerró la carrera de limpieza |
| Baseline de pruebas | 🚧 **En implementación** | Issue #23: contrato + integración + Playwright Chromium path-sensitive |
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
- Coordinación multiagente validada en GitHub real: reserva atómica, UUID por sesión, rechazo de segunda toma, liberación y limpieza idempotente.
- Telemetría de throughput del CI activa; primera medición real: 19 s y artifact con evidencia.
- Baseline técnico de contrato, integración y Playwright preparado sin inventar lógica funcional.

## Archivos de la entrega actual

- `package.json`
- `package-lock.json`
- `playwright.config.mjs`
- `.gitignore`
- `.github/workflows/ci.yml`
- `tests/contract/test_tooling_contract.py`
- `tests/integration/test_tooling_integration.py`
- `tests/e2e/harness.spec.mjs`
- `ESPECIFICACIONES.md`
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
| **AHORA** | 🚧 Baseline de contrato, integración y Playwright, Issue #23. |
| **SIGUE** | 🚧 Definición funcional del producto; protección de `main` sigue bloqueada en Issue #10. |
| **DESPUÉS** | 🚧 Definición funcional del producto + bootstrap técnico de aplicación. |
| **CALIDAD** | 🚧 Baseline de pruebas/Playwright en Issue #23; SonarQube Cloud ya activo. |
| **DEPLOY** | 🚧 Preparar Hostinger y realizar el primer deploy real **V 0.1.0**. |

## Panorama general pendiente

| Frente | Estado |
| --- | --- |
| Gobierno y trazabilidad | 🟢 Base activa; protección de `main` bloqueada en Issue #10 |
| CI base | 🟢 Activo |
| Telemetría de CI | 🟢 Activa; primera medición real 19 s |
| Definición del producto | ⚪ Pendiente |
| Arquitectura | ⚪ Pendiente |
| SonarQube Cloud | 🟢 Activo; ampliar integración con CI |
| Pruebas | 🟡 Baseline técnico en implementación, Issue #23 |
| Seguridad base | ⚪ Pendiente |
| Diseño y UX | ⚪ Pendiente |
| Primer vertical slice | ⚪ Pendiente |
| Hostinger + primer deploy V 0.1.0 | ⚪ Pendiente |
