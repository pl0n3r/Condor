# Cóndor — Especificaciones y decisiones

> Documento de referencia para las reglas, decisiones durables, arquitectura y especificaciones del proyecto.  
> El progreso operativo y acumulativo vive en el roadmap canónico: [Issue #1](https://github.com/pl0n3r/Condor/issues/1).

## 1. Identidad

| Campo | Valor |
|---|---|
| Proyecto | **Cóndor** |
| Repositorio | `pl0n3r/Condor` |
| Rama canónica | `main` |
| Tipo | SaaS de gestión corporativa |
| Mercado inicial | Colombia |
| Idioma principal | Español de Colombia (`es-CO`) |
| Versión inicial de producción | `0.1.0` |

## 2. Regla de idioma

Siempre que sea técnicamente viable, se utilizará español en:

- interfaz y contenido visible para usuarios;
- documentación;
- Issues, Pull Requests, revisiones y comentarios;
- mensajes de commit;
- Releases, milestones, labels y Projects;
- nombres visibles de workflows, jobs y pasos;
- mensajes de pruebas, errores y validaciones destinados a personas.

Se conservarán en inglés los elementos cuyo nombre sea parte de una interfaz técnica o estándar: APIs, librerías, comandos, palabras reservadas, protocolos, paquetes, claves de terceros e identificadores donde traducirlos genere ambigüedad o problemas de compatibilidad.

Los formatos locales partirán de Colombia cuando no exista otro requisito: `es-CO`, COP y formatos de fecha/hora comprensibles para usuarios colombianos.

## 3. Principios de ingeniería

Cóndor adopta las prácticas maduras aprendidas en BRVTAL, sin copiar su lógica de negocio:

- branch → implementación → pruebas dirigidas → PR → CI → Sonar/CodeRabbit → correcciones → squash merge → validación del SHA exacto de `main`;
- paralelización por defecto del trabajo independiente;
- merges a `main` serializados;
- CI selectivo y paralelo;
- pruebas de contrato, integración y Playwright E2E según corresponda;
- preferencia por pruebas de comportamiento;
- usuario E2E aislado cuando existan flujos autenticados;
- seguridad desde el diseño;
- secretos fuera del repositorio;
- ninguna migración destructiva de producción ejecutada automáticamente;
- CI verde no equivale a validación real en producción.

## 4. Infraestructura objetivo inicial

Sujeta a ajuste según las necesidades reales del producto:

- GitHub;
- GitHub Actions;
- SonarCloud;
- CodeRabbit;
- Hostinger shared hosting;
- PHP 8.5;
- MariaDB / MySQL-compatible;
- HTML + CSS + JavaScript con dependencias contenidas;
- Playwright;
- despliegue desde `main` hacia Hostinger;
- separación entre validación de código, observación del despliegue y validación de producción.

## 5. Arquitectura funcional

Pendiente de definición.

Aquí se documentarán, cuando se definan:

- usuarios y roles;
- permisos;
- dominios funcionales;
- módulos;
- navegación;
- modelo de datos;
- integraciones;
- multi-tenancy si aplica;
- contratos internos y públicos;
- límites arquitectónicos.

## 6. Decisiones durables

### D-001 — BRVTAL es referencia de prácticas, no código base

Cóndor reutiliza aprendizajes de infraestructura, automatización, calidad, seguridad y flujo de entrega de BRVTAL.

No se copiarán automáticamente módulos, esquemas de datos, rutas, identidad visual, reglas de negocio ni decisiones específicas del dominio musical.

### D-002 — Separación entre progreso y especificación

- **Issue #1:** roadmap canónico de ejecución y progreso.
- `ROADMAP.md`: acceso visible desde el repositorio hacia el Issue #1; no duplica el estado.
- `ESPECIFICACIONES.md`: reglas, decisiones y detalle funcional/técnico.
- Issues específicos: unidades ejecutables de trabajo y criterios de aceptación.
- PRs: cambios concretos y evidencia de validación.
- `docs/roadmap-historico/`: archivo de etapas cerradas cuando el roadmap activo necesite compactarse.
- `AGENTES.md`: protocolo operativo de desarrollo cuando sea creado.

El roadmap usa la convención heredada de BRVTAL:

- ✅ ~~completado~~;
- 🚧 pendiente / en curso;
- ⛔ bloqueado / dependencia externa.

Los elementos completados permanecen tachados como historial durable.

### D-003 — Español de Colombia como idioma principal

Cóndor está pensado inicialmente para el público colombiano y utilizará `es-CO` como idioma predeterminado en producto y colaboración, salvo excepciones técnicas justificadas.

### D-004 — AGENTES.md hereda las prácticas maduras de BRVTAL

`AGENTES.md` es el protocolo operativo canónico de Cóndor. Toma de BRVTAL la estructura y las reglas que sí aplican: protocolo de arranque, paralelización, roles multidisciplinarios, disciplina de PR/CI, seguridad, testing, gates, estados de producción y mantenimiento del roadmap.

Se excluyen deliberadamente las reglas específicas de DISCADMIN, del dominio musical, rutas, módulos, esquema de datos y decisiones funcionales propias de BRVTAL.

Además, `AGENTES.md` debe reflejar todas las reglas operativas acordadas para Cóndor: español de Colombia, infraestructura objetivo, roadmap en Issue #1, convención visual acumulativa, separación entre roadmap y especificaciones y orientación del roadmap a una lectura ejecutiva para socios.

### D-005 — Versionado por deploy

Cóndor utiliza una versión humana de producto para cada deploy a producción, siguiendo el esquema pre-1.0 utilizado en BRVTAL.

Reglas:

- primer deploy: **`0.1.0`**;
- deploys siguientes: incremento normal de patch, por ejemplo `0.1.1`, `0.1.2`, `0.1.3`;
- un cambio de minor, por ejemplo `0.2.0`, representa un hito deliberado;
- `1.0.0` solo se asignará mediante decisión explícita del usuario;
- una versión no se reutiliza para dos deploys diferentes;
- versión de producto y SHA Git son identidades complementarias, no equivalentes;
- cuando exista el bootstrap de aplicación, `config/version.php` será la fuente canónica;
- si existe `package.json` u otra metadata de versión, deberá mantenerse en paridad con la fuente canónica;
- toda PR deploy-bound debe llevar su bump de versión antes de los gates finales;
- el roadmap mostrará la versión en el título del hito cuando esté asignada;
- un merge/CI exitoso no demuestra por sí mismo que esa versión esté desplegada o validada en producción.

Antes de habilitar el despliegue automático real, cambios preparatorios o puramente documentales no consumen versiones de producción.

### D-006 — README como snapshot del deploy

`README.md` sigue la estrategia operativa de BRVTAL: representa **solo el deploy/snapshot vigente** y se reemplaza en cada PR deploy-bound.

Debe incluir como mínimo:

- versión objetivo;
- **versión desplegada observada**;
- estado del deploy;
- SHA/base exacta relevante;
- huella del cambio cuando sea determinísticamente disponible;
- calidad y gates;
- flujo de entrega;
- qué se hizo;
- archivos modificados en ese deploy;
- validación;
- qué sigue;
- panorama general pendiente resumido con enlace al roadmap canónico.

La versión desplegada se actualiza únicamente con evidencia del despliegue. El primer deploy real de Cóndor será `v0.1.0`; hasta que ocurra, el README debe mostrar que no existe versión desplegada y mantener `v0.1.0` como versión objetivo.

El README no sustituye el roadmap, `AGENTES.md` ni `ESPECIFICACIONES.md`.

## 7. Criterio de actualización

Una decisión debe incorporarse aquí cuando afecte de manera durable cómo se diseña, implementa, prueba, opera o evoluciona Cóndor.

El progreso, estado y orden de ejecución deben actualizarse en el [roadmap canónico — Issue #1](https://github.com/pl0n3r/Condor/issues/1), no duplicarse aquí.
