# Condor — Especificaciones y decisiones

> Documento de referencia para las reglas, decisiones durables, arquitectura y especificaciones del proyecto.  
> El progreso operativo y acumulativo vive en el roadmap canónico: [Issue #1](https://github.com/pl0n3r/Condor/issues/1).

## 1. Identidad

| Campo | Valor |
|---|---|
| Nombre oficial | **Condor App** |
| Nombre corto | **Condor** |
| Dominio canónico | `https://www.condorapp.com.co` |
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

Condor adopta las prácticas maduras aprendidas en BRVTAL, sin copiar su lógica de negocio:

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

Condor reutiliza aprendizajes de infraestructura, automatización, calidad, seguridad y flujo de entrega de BRVTAL.

No se copiarán automáticamente módulos, esquemas de datos, rutas, identidad visual, reglas de negocio ni decisiones específicas del dominio musical.

### D-002 — Separación entre progreso y especificación

- **Issue #1:** roadmap canónico de ejecución y progreso.
- `ROADMAP.md`: acceso visible desde el repositorio hacia el Issue #1; no duplica el estado.
- `ESPECIFICACIONES.md`: reglas, decisiones y detalle funcional/técnico.
- Issues específicos: unidades ejecutables de trabajo y criterios de aceptación.
- PRs: cambios concretos y evidencia de validación.
- `docs/roadmap-historico/`: snapshots o copias auxiliares; nunca sustituye ni recorta el roadmap canónico.
- `AGENTES.md`: protocolo operativo de desarrollo cuando sea creado.

El roadmap usa la convención heredada de BRVTAL:

- ✅ ~~completado~~;
- 🚧 pendiente / en curso;
- ⛔ bloqueado / dependencia externa.

Los elementos completados permanecen tachados como historial durable.

### D-003 — Español de Colombia como idioma principal

Condor está pensado inicialmente para el público colombiano y utilizará `es-CO` como idioma predeterminado en producto y colaboración, salvo excepciones técnicas justificadas.

### D-004 — AGENTES.md hereda las prácticas maduras de BRVTAL

`AGENTES.md` es el protocolo operativo canónico de Condor. Toma de BRVTAL la estructura y las reglas que sí aplican: protocolo de arranque, paralelización, roles multidisciplinarios, disciplina de PR/CI, seguridad, testing, gates, estados de producción y mantenimiento del roadmap.

Se excluyen deliberadamente las reglas específicas de DISCADMIN, del dominio musical, rutas, módulos, esquema de datos y decisiones funcionales propias de BRVTAL.

Además, `AGENTES.md` debe reflejar todas las reglas operativas acordadas para Condor: español de Colombia, infraestructura objetivo, roadmap en Issue #1, convención visual acumulativa, separación entre roadmap y especificaciones y orientación del roadmap a una lectura ejecutiva para socios.

### D-005 — Versionado por deploy

Condor utiliza una versión humana de producto para cada deploy a producción, siguiendo el esquema pre-1.0 utilizado en BRVTAL.

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

La versión desplegada se actualiza únicamente con evidencia del despliegue. El primer deploy real de Condor será `v0.1.0`; hasta que ocurra, el README debe mostrar que no existe versión desplegada y mantener `v0.1.0` como versión objetivo.

El README no sustituye el roadmap, `AGENTES.md` ni `ESPECIFICACIONES.md`.

### D-007 — Glosario para seguimiento de negocio

`GLOSARIO.md` es la referencia en lenguaje sencillo para términos técnicos que aparezcan en README, roadmap, Issues o documentación visible para socios.

Su objetivo es permitir que una persona no técnica entienda el avance y las evidencias del proyecto sin depender de explicaciones externas.

Reglas:

- explicar términos con lenguaje de negocio;
- incluir contexto de Condor cuando ayude;
- mantenerlo actualizado cuando aparezcan conceptos técnicos relevantes nuevos;
- no convertirlo en documentación de implementación;
- enlazarlo desde el README.

### D-008 — Roadmap acumulativo e inmutable hasta v1.0.0

El roadmap canónico en GitHub Issue #1 funciona como **ledger histórico append-only** del desarrollo de Condor.

Reglas:

- no borrar tareas, hitos, fases o entradas ya registradas;
- no retirar del Issue #1 trabajo completado para “limpiar” la vista;
- agregar trabajo nuevo como nuevas entradas;
- cambiar a ✅ y tachar lo completado, conservando su texto y contexto;
- conservar bloqueos resueltos como parte del historial cuando hayan sido relevantes;
- registrar, cuando exista, versión, PR, merge SHA, validación exacta de `main`, despliegue y validación de producción;
- mantener este nivel de trazabilidad desde el arranque hasta, como mínimo, la primera **v1.0.0 madura**;
- si el Issue #1 alcanza un límite práctico de tamaño, crear un Issue/volumen de continuación sin borrar ni reescribir el histórico anterior; ambos deben quedar enlazados;
- la vista para socios debe seguir siendo legible mediante secciones, fases y resúmenes, pero nunca sacrificando el histórico;
- `docs/roadmap-historico/` puede usarse para snapshots o copias auxiliares, pero **nunca como sustituto que permita retirar entradas del roadmap canónico**.

### D-009 — Versión obligatoria en títulos de GitHub

Todo artefacto de GitHub con título humano controlado por el proyecto debe incluir al final la versión objetivo en formato exacto **`(V X.Y.Z)`**.

Aplica, como mínimo, a Issues, Pull Requests, Releases, Milestones y cualquier superficie equivalente de tracking visible.

Reglas:

- durante la preparación inicial se usa `(V 0.1.0)`;
- la versión del título indica bucket/objetivo de tracking, no que esa versión esté desplegada;
- después de un deploy, el trabajo nuevo usa normalmente la siguiente versión objetivo;
- incluso PRs documentales llevan la versión objetivo;
- no usar variantes de formato en títulos;
- `1.0.0` continúa requiriendo decisión explícita del usuario;
- los títulos históricos pueden normalizarse retroactivamente para mantener tracking consistente.

### D-010 — Roadmap exclusivo de trabajo y progreso

El Issue #1 debe contener **únicamente información de trabajo y progreso**.

Puede contener:

- fases;
- tareas e hitos;
- estado ✅ / 🚧 / ⛔;
- versiones objetivo;
- Issues/PRs relacionados;
- merge SHA;
- evidencia de validación, deploy o producción;
- bloqueos y su resolución;
- resúmenes de progreso que cambien con el estado real del proyecto.

No debe contener texto permanente de referencia, como:

- políticas;
- convenciones;
- manuales;
- reglas de idioma/versionado/GitHub;
- instrucciones para agentes;
- explicaciones de cómo funciona el roadmap;
- criterios de seguridad o entrega que no sean una tarea ejecutable.

Ese contenido vive en `AGENTES.md`, `ESPECIFICACIONES.md` o `GLOSARIO.md`.

La regla append-only de D-008 aplica a **entradas de trabajo e historial de ejecución**. Retirar o mover del roadmap texto normativo fijo no constituye pérdida de historial y debe hacerse cuando mantenga la vista más limpia para socios.

### D-011 — Nombre oficial Condor App y nombre corto Condor

La identidad oficial del producto es **Condor App**.

Reglas:

- usar **Condor App** en superficies de marca, identidad pública, presentación comercial y referencias al nombre oficial del producto;
- usar **Condor** como nombre corto en documentación técnica, GitHub, conversaciones de desarrollo, código y referencias internas;
- no usar variantes con tilde como nombre del producto o del proyecto;
- el repositorio permanece como `pl0n3r/Condor`;
- el dominio canónico es **https://www.condorapp.com.co**;
- esta convención aplica a documentación existente y futura.

### D-012 — Coordinación multiagente con reserva atómica

GitHub es la autoridad central para coordinar múltiples cuentas de IA, agentes y sesiones de desarrollo.

Reglas:

- todo trabajo de implementación parte de un Issue abierto marcado `estado: disponible`;
- el comando `/tomar` intenta crear de forma atómica `trabajo/issue-N`; la creación de esa rama funciona como lock distribuido;
- solo una reserva puede existir para un Issue;
- una reserva es fail-closed y no expira automáticamente;
- cada reserva recibe un UUID de sesión único;
- `/liberar <UUID>` libera una reserva normal, `/transferir <UUID>` rota explícitamente el ID para otra sesión y `/liberar-forzado` queda restringido al dueño del repositorio;
- compartir una cuenta de GitHub no permite a dos sesiones asumir simultáneamente la misma reserva;
- cada PR debe corresponder a su rama `trabajo/issue-N`, incluir `Closes #N` y declarar el UUID activo como `Reserva: <UUID>`;
- los marcadores de reserva solo son confiables si los publica la identidad automatizada `github-actions[bot]`;
- CI compara archivos contra otros PR abiertos y falla ante solapamientos para evitar sobrescrituras silenciosas;
- los estados visibles de la cola son disponible, reservado, en revisión, completado, cancelado y bloqueado;
- los merges a `main` permanecen serializados aunque el trabajo previo pueda ejecutarse en paralelo;
- las ramas paralelas deben minimizar cambios en archivos globales compartidos;
- el snapshot de README se actualiza al convertir un PR en candidato serial de merge/deploy, no al inicio de todas las ramas paralelas.

### D-013 — Relay detallado de Sonar en Pull Requests

Los resultados de SonarQube Cloud no deben limitarse al estado del Quality Gate.

Reglas:

- cada check `SonarCloud Code Analysis` completado debe activar el relay canónico;
- el relay publica o actualiza un único comentario en la PR;
- el comentario incluye resultado, SHA, cantidad de anotaciones y hasta 50 hallazgos con severidad, archivo, línea, título y detalle disponible;
- si Sonar no expone anotaciones por línea, el comentario debe indicarlo y enlazar al análisis completo;
- el relay es informativo y no sustituye al Quality Gate de Sonar.

### D-014 — Telemetría de throughput no bloqueante

El tiempo del CI se mide **después** de cada ejecución canónica mediante un workflow observador separado, sin añadir latencia a la ruta crítica.

Reglas:

- comparar únicamente ejecuciones del mismo tipo de evento;
- usar como línea base la mediana de hasta cinco ejecuciones exitosas anteriores;
- exigir al menos tres muestras antes de clasificar una regresión;
- marcar regresión solo si el wall time supera simultáneamente **25%** y **15 segundos** frente a la mediana;
- un CI fallido no se clasifica como regresión de velocidad;
- la telemetría genera Job Summary y evidencia JSON retenida 30 días;
- una regresión de throughput genera advertencia, no bloquea por sí sola el merge;
- optimizaciones de runners, sharding o topología deben apoyarse en esta evidencia antes de añadir complejidad.

### D-015 — Baseline de pruebas antes del producto funcional

Condor establece infraestructura de pruebas antes de implementar módulos funcionales, sin fingir cobertura de producto inexistente.

Reglas:

- pruebas de **contrato** protegen interfaces técnicas estables consumidas por personas, agentes y automatizaciones;
- pruebas de **integración** ejercitan herramientas reales a través de fronteras de proceso/archivo;
- Playwright usa **Chromium** como navegador primario del CI;
- WebKit permanece configurado para cobertura dirigida cuando exista un riesgo real de compatibilidad, sin duplicar permanentemente el costo del CI;
- el harness E2E inicial valida que el runner, navegador, semántica accesible y selectores funcionen, pero no se presenta como validación de Condor App;
- cuando exista autenticación, los E2E reales usarán un usuario aislado/desechable;
- las pruebas E2E priorizan roles accesibles y `data-testid` estables;
- `package-lock.json` es obligatorio para reproducibilidad;
- dependencias de test se fijan a versiones exactas cuando afectan browsers/runner;
- E2E se ejecuta de forma path-sensitive; contrato e integración permanecen como gates rápidos paralelos;
- artifacts de Playwright se conservan solo ante fallos;
- no se usan assertions frágiles de texto fuente como sustituto de comportamiento verificable.

## 7. Criterio de actualización

Una decisión debe incorporarse aquí cuando afecte de manera durable cómo se diseña, implementa, prueba, opera o evoluciona Condor.

El progreso, estado y orden de ejecución deben actualizarse en el [roadmap canónico — Issue #1](https://github.com/pl0n3r/Condor/issues/1), no duplicarse aquí.
