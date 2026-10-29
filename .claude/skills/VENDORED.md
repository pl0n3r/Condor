# Skills de terceros (copias fijadas)

Skills de diseño de frontend copiadas aquí **sin modificar**, para que los agentes de Condor las usen al rehacer el sitio público. Se revisaron a mano antes de incluirlas (solo markdown; sin hooks, scripts ni binarios).

| Carpeta | Origen | Commit | Licencia |
| --- | --- | --- | --- |
| `gsap-*` (8 skills) | https://github.com/greensock/gsap-skills (`skills/`) | `aed9cfd3277740755f6bfc1155c7aa645403b760` | MIT |
| `make-interfaces-feel-better` | https://github.com/jakubkrehel/make-interfaces-feel-better (`skills/`) | `35545ea1512ad59fa463e6b1f95ca9c052981fe6` | MIT |
| `transitions-dev`, `transitions-polish` | https://github.com/Jakubantalik/transitions.dev (`skills/`) | `6a7d68260d624eb66661cc3af17e0989476f48ae` | Licencia propia: uso comercial libre, no se puede redistribuir la librería |
| `impeccable` | https://github.com/pbakaus/impeccable (`plugin/skills/impeccable`) | `5e745ace7e151feb2a93d360d0281fe39448b857` | Apache 2.0 (ver `_licenses/impeccable.NOTICE.md`) |

Licencias completas en `_licenses/`.

## Qué se excluyó a propósito

- `impeccable/scripts/`: contiene el lanzador que descarga y ejecuta un binario, y el modo `live` que inyecta un script en la página. Tampoco se instalan sus hooks (`SessionStart`, `PostToolUse`, `Stop`).
- Sin ese lanzador, el paso «Setup» de la skill cae en su ruta documentada «Launcher unavailable» y lee `PRODUCT.md` / `DESIGN.md` directamente. Los comandos `audit`, `critique`, `polish`, `animate`, etc. siguen funcionando; `live` y `doctor` no.
- `transitions-ui-generation` y `refine-live` de Transitions.dev: incluyen JavaScript.

## Cómo actualizar

No actualizar automáticamente. Para subir de versión: clonar el origen, leer el diff de los `SKILL.md`, sustituir la carpeta, actualizar el commit de esta tabla y abrir un PR.
