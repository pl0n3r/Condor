# Condor — capa local para agentes

> **Protocolo global obligatorio:** https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md
>
> **Núcleo operativo Factory v1:** https://github.com/pl0n3r/factory/blob/v1/agentes/NUCLEO.md
>
> Este archivo contiene solo la capa propia de Condor. Si una regla local contradice una decisión activa de `decisiones.yml` o el protocolo global, gana la fuente de mayor precedencia.

## 1. Fuentes de verdad locales

- `decisiones.yml`: decisiones activas del dueño, normativas y verificadas por Factory.
- Código fusionado en `main` + pruebas: implementación actual.
- `ESPECIFICACIONES.md`: decisiones durables de producto y arquitectura.
- Issue #1: Roadmap canónico, cronológico y acumulativo.
- Issues/PR activos: alcance ejecutable.
- `README.md`: snapshot del deploy/candidato vigente.
- `GLOSARIO.md`: términos técnicos explicados para negocio.
- `docs/`: documentación especializada.

No convertir AGENTES.md en Roadmap, changelog ni copia del núcleo Factory.

## 2. Arranque específico de Condor

Además del arranque definido por Factory:

1. comprobar el SHA exacto de `main`;
2. revisar Issue #1 y el Issue reservado;
3. comprobar PRs abiertos y colisiones de archivos;
4. revisar `Validar`, Sonar/CodeQL/CodeRabbit y el observer del SHA relevante;
5. confirmar el estado productivo separado del estado de CI;
6. si producción deja de estar GREEN, priorizar su recuperación antes de trabajo dependiente.

La coordinación canónica se consume desde Factory v1. Las ramas normales siguen siendo `trabajo/issue-N` y una reserva stale se recupera sobre el mismo Issue/rama.

## 3. Contexto técnico

| Elemento | Contrato |
| --- | --- |
| Producto | Condor App / Condor |
| Dominio | https://www.condorapp.com.co |
| Mercado / locale | Colombia / es-CO |
| Moneda por defecto | COP |
| Backend | PHP 8.5 + Symfony 7.4 LTS |
| Datos | MariaDB/MySQL-compatible + Doctrine |
| Admin | React + TypeScript + Vite |
| Público/SEO | Twig/Symfony SSR |
| Hosting | Hostinger shared hosting |
| E2E | Playwright / Chromium |
| Fase actual | `construccion` |

Evitar procesos Node permanentes y Docker como requisito del runtime mientras Hostinger shared hosting sea la plataforma. Mantener portabilidad y secretos fuera del repositorio.

## 4. Arquitectura y producto

- servidor como autoridad final de validación y autorización;
- multi-tenancy como invariante testeada, no filtro de UI;
- relaciones de negocio como datos estructurados;
- Doctrine/consultas parametrizadas;
- transacciones y locking cuando varias escrituras/concurrencia puedan romper invariantes;
- migraciones explícitas, expand-compatible y seguras ante despliegues parciales;
- frontend y backend evolucionan por vertical slices reales;
- mobile-first, accesibilidad y estados loading/empty/error/permission-denied;
- superficie pública sobria, corporativa y premium, sin sacrificar SSR/SEO/rendimiento.

Las decisiones funcionales nuevas viven en `ESPECIFICACIONES.md`, no aquí.

## 5. CI: Factory común + extensión Condor

Factory v1 provee el núcleo común de:

- CI base;
- coordinación;
- etiquetas;
- política de decisiones/revisiones;
- releases.

Condor conserva como extensión local mientras sigan aportando cobertura propia:

- clasificador selectivo de cambios;
- validación documental y gobierno local;
- contratos e integración;
- TypeScript/build;
- auditorías npm/Composer;
- análisis estático PHP con PHPStan nivel 8 + baseline versionada;
- backend PHP/MariaDB;
- backup + restauración real;
- Playwright/runtime;
- observer y transición productiva hasta el slice de deploy/rollback #227.

El check agregado `Validar` debe exigir el CI Factory y los gates locales aplicables. Un check `skipped` solo cuenta cuando el clasificador o el contexto demuestra que no aplica.

Antes de un push que toque PHP o tooling:
- `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G` debe quedar limpio contra la baseline vigente.
- `vendor/bin/rector process --dry-run --no-progress-bar` debe quedar limpio para el alcance configurado y es gate obligatorio de CI; ampliar sus paths/reglas solo en lotes pequeños, revisados y con regresión.
- La baseline de PHPStan representa deuda heredada, no permiso para añadir errores nuevos.

## 6. Versionado y entrega

Fuente canónica: `config/version.php`.

- cada deploy productivo identificable usa versión humana;
- incremento normal pre-1.0: patch;
- no reutilizar una versión para dos deploys distintos;
- 1.0.0 requiere decisión explícita del dueño;
- versión y SHA son identidades complementarias;
- una PR deploy-bound contiene su versión objetivo antes de gates finales.

Estados: IMPLEMENTADO → VALIDADO EN CÓDIGO → DESPLEGADO → VALIDADO EN PRODUCCIÓN. Merge/CI verde nunca equivalen automáticamente a producción.

README representa solo el candidato/deploy vigente y se actualiza cuando el PR es el siguiente candidato serial.

## 7. Producción en Hostinger

Condor está en **CONSTRUCCIÓN** mientras no existan usuarios finales o datos reales a conservar como operación de negocio.

D-054 gobierna la convergencia de esquema:

- solo migraciones versionadas, aditivas/expand-compatible;
- dry-run/allowlist;
- backup exitoso previo;
- opt-out `CONDOR_AUTO_MIGRATE=0`;
- migrate + recheck antes de tocar caché;
- si backup o validación fallan, se falla cerrado.

Hostinger preserva `var/cache/prod` entre builds y no ofrece post-deploy nativo (Issue #144). `public/index.php` usa `ContainerRecovery` para la primera solicitud afectada y el cron `*/5 * * * * scripts/post-deploy.sh` cubre el resto.

Antes de OPERACIÓN REAL, el dueño debe declarar explícitamente el cambio y `CONDOR_PRODUCTION_STAGE=live`.

Siempre requieren autorización explícita: SQL/migraciones destructivas, borrado irreversible, rotación de secretos reales y cambios DNS/infra irreversibles o sin rollback razonable.

## 8. Carga y coordinación con varios agentes

- No hacer polling de checks, comentarios ni workflows: una lectura del CI tras entregar el PR y nuevos chequeos solo por hito verificable.
- Agrupar los pushes por bloque lógico y evitar comentarios de progreso sin resultado.
- Capacidad operativa de **2–3 agentes simultáneos entre repos distintos**; máximo **uno por repo** (Factory admite el segundo agente solo en los archivos exclusivos previstos por el protocolo). Cada trabajo conserva su reserva válida y evita colisiones.
- La telemetría CI se muestrea una vez por hora; no dispara una corrida por cada CI ni duplica artifacts para el mismo run.
- Relay de Sonar exclusivamente manual: SonarCloud ya publica Quality Gate nativo.

## 9. Roadmap, incidentes y handoff

Issue #1 es el único Roadmap activo; los comentarios registran hitos macro, no micro-logs.

Ante fallo productivo o CI determinista: separar código, deploy, esquema, configuración, caché/container y estado persistente; empezar read-only; confirmar causa antes de mutar; corregir mínimo; repetir la superficie fallida; añadir regresión cuando exista un límite estable.

Antes de transferir trabajo:

- dejar Issue/PR con estado, evidencia y siguiente acción;
- conservar/transferir/liberar el UUID correctamente;
- indicar el SHA relevante de `main`;
- no afirmar producción validada sin observer/smoke real.
