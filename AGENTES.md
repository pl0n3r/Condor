# Condor — contexto operativo canónico para agentes

> **ESTE ARCHIVO ES EL PUNTO DE ARRANQUE OBLIGATORIO PARA CHATGPT, CODEX Y CUALQUIER AGENTE DE DESARROLLO QUE TRABAJE EN CONDOR.**
>
> Leer este archivo completo antes de modificar el proyecto. No se debe depender de conversaciones anteriores, memoria externa ni resúmenes humanos para continuar el trabajo.
>
> Condor adopta deliberadamente las prácticas maduras de ingeniería de BRVTAL cuando son aplicables, pero **no copia su lógica de negocio, arquitectura funcional, rutas, módulos ni deuda histórica**.
>
> **Identidad:** el nombre oficial y público del producto es **Condor App**. En documentación técnica, GitHub, conversaciones de desarrollo y referencias internas se usa **Condor** como nombre corto.

## 0. Responsabilidad de cada fuente

Condor separa explícitamente operación, especificación y progreso:

- **Código fusionado en `main` + pruebas**: verdad de implementación.
- **`AGENTES.md`**: cómo se trabaja.
- **`ESPECIFICACIONES.md`**: decisiones durables, reglas funcionales y arquitectura.
- **[Issue #1 — Roadmap canónico](https://github.com/pl0n3r/Condor/issues/1)**: log exclusivo de trabajo y progreso: qué está planeado, en curso, bloqueado o terminado y en qué orden se ejecuta.
- **Issues específicos**: alcance ejecutable y criterios de aceptación.
- **PRs**: cambio concreto y evidencia de validación.
- **`README.md`**: presentación visual y ejecutiva del proyecto; no es roadmap acumulativo ni especificación.
- **`GLOSARIO.md`**: traducción de términos técnicos a lenguaje de negocio para socios y personas no técnicas.
- **`docs/`**: documentación especializada cuando el detalle ya no cabe razonablemente en las fuentes anteriores.

Si una fuente contradice al código actual, contrastar el cambio más reciente y corregir la documentación durable en el mismo trabajo cuando corresponda.

---

## 1. Protocolo de inicio de cada sesión

En cada sesión de desarrollo:

1. Leer `AGENTES.md` completo.
2. Obtener el SHA exacto actual de `main`.
3. Revisar PRs abiertos.
4. Revisar los gates/checks del PR activo y del SHA relevante de `main` cuando existan.
5. Leer el [roadmap canónico — Issue #1](https://github.com/pl0n3r/Condor/issues/1).
6. Leer el Issue específico que corresponda al siguiente trabajo.
7. Consultar únicamente las especificaciones/documentos del área necesaria.
8. Si un PR abierto ya cubre la tarea, continuar o corregir ese PR en vez de duplicarlo.
9. Si un gate obligatorio de `main` está fallando por una causa del repositorio, cerrar ese problema antes de abrir trabajo dependiente nuevo.
10. Seguir el flujo **rama → implementación → pruebas → PR → gates → correcciones → squash merge → verificación del SHA exacto de `main`**.
11. Actualizar el roadmap cuando cambie el estado real de una tarea o hito.
12. Actualizar `ESPECIFICACIONES.md` cuando cambie una decisión durable de producto o arquitectura.

No preguntar por operaciones rutinarias que puedan resolverse con seguridad desde el repositorio. Detenerse únicamente cuando haga falta:

- una credencial o permiso no disponible;
- una decisión de producto genuinamente ambigua e imposible de deducir;
- una operación irreversible o destructiva en producción;
- acceso a datos sensibles o una acción protegida que requiera autorización explícita.

## 1.1. Reserva obligatoria y coordinación multiagente

GitHub es el **árbitro central de la cola de trabajo**. Después de que esta capacidad esté fusionada en `main`, ninguna sesión, cuenta de IA o agente puede empezar implementación nueva sin reservar primero un Issue.

### Tomar trabajo

1. Elegir únicamente un Issue abierto con label `estado: disponible`.
2. Comentar exactamente `/tomar` en ese Issue.
3. Esperar la respuesta del workflow de coordinación.
4. La reserva exitosa crea de forma atómica la rama canónica `trabajo/issue-N`, cambia el Issue a `estado: reservado` y publica quién lo tomó.
5. Si otra sesión intenta reservar el mismo Issue, la creación atómica de la rama actúa como lock: solo una puede ganar.
6. Si la reserva es rechazada, **no trabajar esa tarea**; elegir otro Issue disponible.

### Propiedad de la reserva

- una reserva no vence automáticamente: el sistema es **fail-closed**;
- compartir la misma cuenta de GitHub **no** autoriza a dos sesiones a trabajar el mismo Issue;
- una sesión solo puede continuar una reserva si la creó en la sesión actual o fue invocada explícitamente para retomar ese Issue;
- nunca modificar `trabajo/issue-N` si pertenece a otra sesión/agente;
- nunca crear ramas alternativas para saltarse una reserva existente;
- para liberar normalmente, comentar `/liberar`;
- el dueño del repositorio puede resolver una reserva huérfana con `/liberar-forzado`.

### Pull Requests y colisiones

- después del primer commit lógico, abrir el PR pronto para hacer visible el alcance en curso;
- el PR debe salir de `trabajo/issue-N` e incluir `Closes #N` en el cuerpo;
- CI valida la reserva y compara los archivos del PR contra todos los demás PR abiertos hacia `main`;
- si dos PR modifican el mismo archivo, la validación de coordinación falla y el trabajo debe repartirse, serializarse o actualizarse sobre el nuevo `main`;
- nunca resolver una colisión sobrescribiendo silenciosamente el trabajo de otra sesión;
- archivos globales como `README.md`, `AGENTES.md`, `ESPECIFICACIONES.md`, `GLOSARIO.md` y workflows deben tocarse solo cuando el Issue lo requiera;
- los merges a `main` continúan siendo estrictamente seriales.

### README con trabajo paralelo

Para evitar que todas las ramas paralelas colisionen por el snapshot del README, una rama de trabajo normal **no actualiza README prematuramente**. Cuando ese PR pase a ser el siguiente candidato serial de merge/deploy, debe sincronizarse con el `main` más reciente y actualizar el snapshot requerido antes de sus gates finales.

---

## 2. Paralelización por defecto

La paralelización es el modo operativo normal cuando reduce tiempo sin aumentar riesgo.

- Ejecutar en paralelo lecturas independientes, inspección de código, análisis, preparación de pruebas y revisión de gates.
- Si existen dos o más operaciones de solo lectura independientes, agruparlas en el mismo lote cuando la herramienta lo permita.
- Revisar en paralelo, cuando existan, estado del PR, checks del head exacto, CI, SonarCloud, CodeRabbit, comentarios y threads.
- Mientras un gate externo procesa, aprovechar el tiempo para análisis de solo lectura de trabajo independiente.
- Se permiten hasta **4 líneas de trabajo concurrentes** solo cuando correspondan a Issues distintos, cada uno con reserva válida, y no compitan por los mismos archivos o estado mutable.
- Las escrituras sobre el mismo archivo, ramas dependientes o estado compartido se serializan.
- Los merges a `main` son **siempre seriales**.
- Antes de cada merge, volver a comprobar `main`, head exacto del PR y gates aplicables.
- Si `main` cambió, recontrastar el PR antes de fusionar.
- Agrupar múltiples archivos relacionados en commits lógicos; evitar tormentas de commits que reinicien CI/reviews innecesariamente.
- Migraciones de producción, acciones destructivas y operaciones protegidas nunca se paralelizan ni se ejecutan automáticamente.

---

## 3. Rol operativo multidisciplinario

El agente actúa por defecto como **ingeniero principal y ejecutor técnico de extremo a extremo**.

Según la tarea, combinar estas capacidades sin convertirlas en etapas burocráticas separadas:

- **Arquitectura / Product Engineering** — elegir la solución mínima mantenible que satisfaga el producto, proteger límites claros y evitar sistemas duplicados.
- **Backend / Data Engineering** — contratos, persistencia, integridad, migraciones seguras, transacciones y consistencia.
- **Frontend / UX / UI** — responsive, interacción, accesibilidad, jerarquía de información y calidad visual.
- **Diseño de producto** — mantener una experiencia coherente, profesional y adecuada al mercado colombiano, evitando interfaces genéricas o inconsistentes.
- **QA / Test Automation** — anticipar regresiones, cubrir invariantes y utilizar E2E realista cuando sea práctico.
- **Application Security** — autenticación, autorización, sesión, CSRF, XSS/inyección, secretos, límites de confianza y findings de seguridad.
- **Performance / Reliability** — medir caminos críticos, reducir serialización evitable, distinguir fallos internos de dependencias externas y mantener estados de error/reintento explícitos.
- **DevOps / Release Engineering** — CI/CD, gates, release identity, observabilidad del despliegue y evidencia exacta de cada entrega.

El objetivo es responsabilizarse del recorrido completo:

**instrucción → diagnóstico → diseño → implementación → pruebas → PR → revisión → merge → validación exacta de main → observación de despliegue → validación de producción cuando exista evidencia real**.

---

## 4. Idioma y mercado

Condor está orientado inicialmente a Colombia.

### Regla de idioma

Usar **español de Colombia (`es-CO`)** siempre que sea técnicamente viable en:

- interfaz de usuario;
- mensajes, errores, validaciones, estados vacíos y ayudas;
- documentación;
- Issues y sus comentarios;
- PRs, reviews, respuestas y comentarios;
- mensajes de commit;
- Releases, milestones, Projects y labels;
- nombres visibles de workflows, jobs y pasos de GitHub Actions;
- textos de pruebas visibles para personas;
- comentarios de código cuando realmente sean necesarios.

Mantener en inglés los elementos donde traducir reduzca compatibilidad o precisión: APIs, librerías, comandos, palabras reservadas, protocolos, formatos estándar, nombres de paquetes, claves de terceros e identificadores externos.

### Convenciones locales

Cuando no exista otro requisito explícito:

- locale: `es-CO`;
- moneda: COP;
- textos naturales para usuarios colombianos;
- fechas y horas comprensibles para Colombia;
- zona horaria y reglas temporales deben definirse explícitamente antes de persistir lógica sensible al tiempo.

---

## 5. Identidad e infraestructura inicial

| Elemento | Valor actual |
|---|---|
| Nombre oficial del producto | **Condor App** |
| Nombre corto técnico/interno | **Condor** |
| Dominio canónico | `https://www.condorapp.com.co` |
| Repositorio | `pl0n3r/Condor` |
| Rama canónica | `main` |
| Tipo | SaaS de gestión corporativa |
| Mercado inicial | Colombia |
| Hosting objetivo inicial | Hostinger shared hosting |
| Backend objetivo inicial | PHP 8.5 |
| Base de datos objetivo inicial | MariaDB / MySQL-compatible |
| Frontend objetivo inicial | HTML + CSS + JavaScript con dependencias contenidas |
| Navegador / E2E | Playwright |
| CI | GitHub Actions — activo |
| Calidad | SonarQube Cloud + CodeRabbit — activos; ampliación de gates en progreso |
| Deploy objetivo | GitHub `main` → Hostinger |
| Versión inicial de producto | `0.1.0` |

Estos valores son una base, no una obligación eterna. Si las necesidades reales del producto justifican un cambio de arquitectura o plataforma, documentarlo primero en `ESPECIFICACIONES.md` y reflejar el trabajo en el roadmap.

Mientras Hostinger shared hosting sea la plataforma elegida:

- evitar requerir procesos Node de larga duración en producción;
- evitar Docker como requisito de runtime;
- no convertir FTP/manual deploy en el flujo normal;
- separar despliegue de código de migraciones de base de datos.

---

## 6. Principios de arquitectura y datos

Hasta que el dominio funcional esté completamente definido:

- evitar sobrearquitectura;
- construir vertical slices completos antes de crear abstracciones sin uso;
- una sola responsabilidad clara por módulo/capa;
- relaciones de negocio como datos estructurados, no texto duplicado;
- servidor como autoridad final de validación;
- permisos/autorización verificados en backend;
- parametrizar consultas SQL;
- utilizar transacciones cuando varias escrituras deban ser atómicas;
- utilizar locking o estrategia equivalente cuando la concurrencia pueda romper invariantes;
- diferenciar borrador/incompleto de publicado/activo cuando el dominio lo requiera;
- diseñar migraciones explícitas, idempotentes cuando sea razonable y seguras ante reejecución;
- no asumir que fusionar código significa que una migración corrió en producción;
- no inferir multi-tenancy: definirlo antes de implementar aislamiento de datos;
- si se adopta multiempresa, el aislamiento debe ser una propiedad arquitectónica y testeada, no un filtro de UI.

Las decisiones durables pertenecen a `ESPECIFICACIONES.md`.

---

## 7. Seguridad mínima no negociable

La seguridad se trata como requisito de entrega, no como etapa posterior.

Cuando aplique:

- autenticación centralizada;
- autorización server-side;
- regeneración de session ID en transiciones sensibles;
- cookies `HttpOnly`, `Secure` en HTTPS y política `SameSite` apropiada;
- CSRF en operaciones mutables autenticadas;
- passwords con algoritmos modernos de hash;
- rate limiting en login y endpoints sensibles;
- secretos fuera del repositorio;
- errores públicos sin stack traces ni secretos;
- validación y normalización server-side;
- output escaping / sanitización según contexto;
- consultas preparadas;
- uploads validados por tipo, tamaño, ruta y autorización si existen;
- logs sin passwords, tokens ni secretos;
- permisos de mínimo privilegio;
- backups con restauración demostrable, no solo generación de archivos.

Un finding de seguridad válido se corrige en su causa raíz cuando el repositorio puede resolverlo; no se silencia únicamente para pasar una herramienta.

---

## 8. Pruebas y calidad

La estrategia es **behavior-first**.

- Preferir contratos ejecutables sobre búsquedas de strings en código.
- Unit/contract tests para invariantes puros.
- Integration tests para persistencia, API y límites entre capas.
- Playwright para flujos de navegador.
- Cuando exista autenticación, usar un usuario E2E aislado y descartable para tanta cobertura realista como sea razonable.
- Nunca usar credenciales reales de producción en E2E.
- Nunca mutar datos reales de producción para “probar”.
- Chromium será la cobertura de navegador principal; WebKit se añade de forma dirigida cuando el riesgo Safari/iOS lo justifique.
- Toda corrección determinista importante debería ganar una regresión automática si existe un límite estable y razonable para probarla.

### Calidad automática

Cuando estén configurados:

- SonarCloud aplica enfoque Clean as You Code;
- CodeRabbit revisa el head estable previsto para merge, no cada commit intermedio si eso introduce ruido;
- CI, Sonar y CodeRabbit deben solaparse en paralelo siempre que sea seguro;
- findings válidos se corrigen antes del merge;
- si una corrección cambia el head, revalidar los gates afectados en ese head exacto.

No declarar un gate como aprobado mientras siga procesando.

---

## 9. Contrato de CI y entrega

El CI de Condor debe evolucionar hacia un flujo rápido y selectivo inspirado en BRVTAL:

1. **preflight** siempre corto y obligatorio;
2. clasificar archivos modificados;
3. ejecutar en paralelo solo los gates relevantes;
4. mantener un check agregado final estable para protección de `main`;
5. incluir contexto útil en GitHub Actions Job Summary;
6. validar PRs y el SHA exacto de `main`;
7. medir throughput antes de optimizar runner/sharding/topología.

Gates previstos a medida que la aplicación crezca:

- sintaxis/lint/contratos rápidos;
- integración con MariaDB descartable;
- browser Chromium;
- real-stack autenticado cuando exista;
- WebKit dirigido;
- recuperación/backups cuando exista esa superficie;
- seguridad/análisis estático.

No crear workflows duplicados si un gate pertenece naturalmente al CI canónico.

---

## 10. Flujo obligatorio de entrega

Para trabajo normal de código o configuración:

1. partir del `main` exacto actual;
2. elegir un Issue con `estado: disponible`;
3. reservarlo con `/tomar` y esperar confirmación;
4. trabajar exclusivamente en la rama canónica `trabajo/issue-N` creada por la reserva;
5. implementar el cambio lógico;
6. añadir/ajustar pruebas aplicables;
7. ejecutar pruebas dirigidas;
8. abrir PR a `main` desde esa rama e incluir `Closes #N`;
9. ejecutar/revisar CI, coordinación, SonarCloud y CodeRabbit en paralelo cuando estén disponibles;
10. corregir findings válidos en la misma rama;
11. comprobar nuevamente el head exacto y el `main` actual;
12. hacer **squash merge**;
13. obtener el SHA exacto resultante de `main`;
14. validar los gates que correspondan contra ese SHA;
15. observar despliegue por separado;
16. validar producción solo con evidencia real;
17. actualizar el roadmap con el resultado;
18. actualizar especificaciones si cambió una decisión durable.

No iniciar una rama dependiente nueva antes de cerrar la validación de `main` del bloque anterior. El análisis de solo lectura para trabajo futuro sí puede adelantarse.

---

## 11. Versionado de producto y releases

Condor usa versionado de producto explícito por deploy, siguiendo la convención ya adoptada en BRVTAL.

### Regla de versión

- la primera versión de producción de Condor será **`0.1.0`**;
- cada deploy posterior incrementa normalmente el **patch**: `0.1.0 → 0.1.1 → 0.1.2 → ...`;
- un cambio de **minor** pre-1.0, por ejemplo `0.1.x → 0.2.0`, representa un hito deliberado de producto y no debe ocurrir automáticamente;
- **`1.0.0` requiere decisión explícita del usuario**;
- no saltar versiones ni reutilizar una versión que ya haya representado un deploy distinto;
- la versión humana del producto y el SHA Git son identidades diferentes: la versión comunica release de producto; el SHA identifica exactamente el código.

### Deploy-bound PR

Una PR es **deploy-bound** cuando su merge a `main` vaya a activar o formar parte de una entrega a producción.

Para cada PR deploy-bound:

1. asignar exactamente una versión objetivo;
2. reflejar esa versión en el título visible del hito del roadmap cuando entre en implementación/PR;
3. actualizar la fuente canónica de versión del producto;
4. si existe `package.json` u otra metadata de versión, mantener paridad con la fuente canónica;
5. ejecutar los gates sobre el head estable que contiene el bump;
6. hacer squash merge;
7. verificar el SHA exacto resultante de `main`;
8. observar el despliegue de esa versión por separado;
9. registrar versión, PR y evidencia en el roadmap.

Cuando el bootstrap técnico cree la aplicación, la fuente canónica será **`config/version.php`**, siguiendo el patrón de BRVTAL. Si existe `package.json`, su `version` deberá coincidir.

Antes de que el despliegue automático a producción esté habilitado, las PRs puramente documentales o de preparación no consumen versiones de producción. El primer deploy real será `0.1.0`.

### Convención del roadmap

Cuando un hito tenga versión asignada, usar el formato:

`Nombre del hito (V 0.1.0), PR #N`

Al completarse:

`✅ ~~Nombre del hito (V 0.1.0), PR #N~~`

No marcar una versión como desplegada o validada en producción sin evidencia correspondiente.

---

## 12. Estados de entrega

Usar estos conceptos con precisión:

- **IMPLEMENTADO** — el cambio existe en código.
- **VALIDADO EN CÓDIGO** — pruebas/gates requeridos pasaron.
- **DESPLEGADO** — la plataforma de producción recibió la versión.
- **VALIDADO EN PRODUCCIÓN** — se comprobó comportamiento real en producción.

Nunca convertir automáticamente “CI verde” en “VALIDADO EN PRODUCCIÓN”.

---

## 13. Roadmap canónico y convención de progreso

El roadmap activo es **[GitHub Issue #1](https://github.com/pl0n3r/Condor/issues/1)**.

Convención obligatoria, heredada de BRVTAL:

- ✅ ~~Completado y validado por los gates requeridos~~
- 🚧 Pendiente / en curso
- ⛔ Bloqueado / dependencia externa

Reglas:

- todo trabajo planeado relevante debe aparecer en el roadmap;
- todo trabajo completado relevante permanece visible y tachado;
- el roadmap es **append-only**: se agregan entradas nuevas y se actualiza el estado de las existentes, pero no se elimina el historial;
- no borrar trabajo terminado para hacer el roadmap “más limpio”;
- registrar PR y versión cuando exista una release asignada;
- usar en los títulos del roadmap/versionados el formato visual `(V X.Y.Z)` cuando se muestre una versión;
- **no retirar, mover ni borrar del Issue #1 las fases o tareas ya registradas antes de v1.0.0**; el roadmap es un ledger acumulativo;
- una instrucción explícita del usuario puede repriorizar el roadmap;
- Issues específicos contienen criterios de aceptación; el roadmap contiene orden y estado;
- después de un merge relevante, el roadmap debe reflejar la realidad, no el plan anterior;
- hasta alcanzar una **v1.0.0 madura**, conservar un registro detallado de cada bloque relevante: tarea/hito, estado, versión cuando exista, PR, merge SHA, validación exact-main, estado de despliegue y validación de producción cuando exista;
- si el cuerpo del Issue #1 llegara a un límite práctico de tamaño, **no compactar ni borrar**: crear un volumen/Issue de continuación, dejar el Issue #1 intacto y enlazar ambos en ambas direcciones;
- el roadmap debe conservar una lectura limpia para socios y personas no técnicas: mostrar trabajo planeado, hecho, pendiente, bloqueos, fases, PRs/versiones y progreso;
- **no incluir en el roadmap políticas permanentes, manuales, convenciones, instrucciones operativas ni explicaciones que permanezcan fijas**; esos contenidos pertenecen a `AGENTES.md`, `ESPECIFICACIONES.md` o `GLOSARIO.md`;
- la regla append-only aplica a **entradas de trabajo e historial de ejecución**, no al texto normativo: mover o retirar boilerplate/políticas del roadmap está permitido y es obligatorio cuando mejora su limpieza sin borrar trabajo histórico;
- cuando un detalle técnico sea necesario para ejecutar o validar una tarea, llevarlo al Issue específico o a `ESPECIFICACIONES.md` y mantener en el roadmap solo el resumen necesario para entender avance y estado.

---

## 14. Convenciones de GitHub

Todo lo controlable por el proyecto debe estar en español.

### Ramas

Después de activar la coordinación multiagente, el trabajo normal usa **exclusivamente** la rama canónica creada por la reserva:

- `trabajo/issue-12`
- `trabajo/issue-57`

Reglas:

- no crear manualmente ramas `feature/*`, `fix/*`, `docs/*`, `infra/*` o equivalentes para trabajo normal;
- `/tomar` crea `trabajo/issue-N` de forma atómica desde el `main` actual;
- una rama canónica existente significa que el Issue está reservado, incluso si un label tarda en sincronizarse;
- solo se permiten excepciones de bootstrap/mantenimiento cuando están explícitamente documentadas en el Issue correspondiente.

### Commits

Mensajes concisos en español, por ejemplo:

- `feat: agrega autenticación inicial`
- `fix: corrige aislamiento por empresa`
- `docs: actualiza especificaciones de permisos`
- `infra: configura CI inicial`

Términos convencionales como `feat`, `fix`, `docs`, `test`, `refactor`, `perf` e `infra` pueden mantenerse por utilidad técnica.

### Regla de versión en títulos de GitHub

Todo artefacto de GitHub con título humano que podamos controlar debe incluir la versión objetivo al final, usando exactamente:

`(V 0.1.0)`

Aplica a:

- Issues;
- Pull Requests;
- Releases;
- Milestones;
- Discussions o Project items si llegan a usarse y tienen título propio;
- cualquier otro artefacto equivalente de tracking visible para personas.

Reglas:

- la versión del título representa **tracking / versión objetivo**, no evidencia de deploy;
- mientras el proyecto esté preparando la primera entrega, usar `(V 0.1.0)`;
- después de desplegar una versión, el trabajo nuevo pasa normalmente a la siguiente versión objetivo, por ejemplo `(V 0.1.1)`;
- una PR documental también lleva versión en el título aunque por sí sola no “consuma” ni demuestre un deploy;
- no usar variantes como `v0.1.0`, `V0.1.0` o `(v0.1.0)` en títulos de GitHub;
- antes de crear o renombrar un artefacto, comprobar cuál es la versión objetivo vigente;
- al cambiar deliberadamente de minor, todos los títulos nuevos usan la nueva versión objetivo;
- `1.0.0` sigue requiriendo decisión explícita del usuario.

Ejemplos:

- `feat: agrega autenticación inicial (V 0.1.0)`
- `planificación: permisos y roles (V 0.1.0)`
- `release: primer deploy de producción (V 0.1.0)`

### Pull Requests

- título en español y con la versión objetivo al final;
- resumen claro;
- explicar por qué cambia;
- listar validación real;
- no afirmar producción si no fue comprobada;
- vincular Issue cuando exista;
- mantener el head estable para revisión final;
- squash merge como estrategia predeterminada.

### Issues

- título y descripción en español;
- título con la versión objetivo al final en formato exacto `(V X.Y.Z)`;
- problema/objetivo verificable;
- criterios de aceptación cuando corresponda;
- evidencia y limitaciones explícitas;
- evitar duplicados revisando Issues abiertos y roadmap antes de crear uno nuevo.

---

## 15. README por deploy

Condor adopta la misma estrategia de README operativo de BRVTAL: **`README.md` es el snapshot visual del deploy actual**, no un documento acumulativo.

Para cada PR deploy-bound:

- reemplazar el snapshot del README; no anexar un changelog histórico;
- mostrar de forma visible la **versión objetivo** y la **versión desplegada actualmente**;
- después del primer deploy real, la señal `Versión desplegada` debe mostrar siempre la última versión que Hostinger haya recibido de forma verificable, por ejemplo **`v0.1.0`**;
- nunca actualizar `Versión desplegada` solo porque una PR se fusionó o CI quedó verde;
- si todavía no existe evidencia de despliegue, mostrarlo explícitamente y mantener la versión objetivo separada;
- incluir únicamente los archivos modificados por el deploy actual y una explicación breve;
- incluir una sección **Qué se hizo**;
- incluir una sección **Validación** con evidencia real;
- incluir **Qué sigue** en lanes `AHORA / SIGUE / DESPUÉS` o equivalente;
- incluir un **Panorama general pendiente** conciso, enlazado al roadmap canónico, sin duplicarlo por completo;
- incluir una sección exacta **Estado del deploy** con tabla `Señal | Estado | Evidencia`;
- incluir una sección **Flujo de entrega** con diagrama Mermaid cuando el pipeline exista;
- mostrar badges de CI, Sonar y observación de deploy cuando esas superficies estén configuradas;
- mantener visibles enlaces a `AGENTES.md`, `ESPECIFICACIONES.md` y al Issue #1;
- mantener el README visualmente escaneable mediante tablas, estados y símbolos, evitando prosa innecesaria.

El README debe distinguir siempre:

- **versión objetivo** de la PR;
- **versión desplegada** observada;
- SHA exacto de `main`;
- estado de validación en código;
- estado de validación en producción.

No usar README como:

- roadmap acumulativo;
- changelog completo;
- archivo de decisiones técnicas;
- reemplazo de `AGENTES.md` o `ESPECIFICACIONES.md`.

El historial de releases se conserva mediante Git/PRs/releases/roadmap; el README muestra solamente el snapshot operativo vigente.

---

## 16. Producción y operaciones protegidas

Nunca ejecutar automáticamente:

- SQL destructivo en producción;
- resets/seeds de producción;
- borrado irreversible de datos;
- rotación de secretos reales;
- cambios de DNS/infraestructura irreversibles;
- acciones que puedan interrumpir producción sin una vía segura de rollback;
- migraciones productivas que no estén explícitamente autorizadas.

El despliegue de código y la migración de datos son operaciones distintas.

Ante un fallo recurrente:

1. determinar si es determinista, de timing o externo;
2. corregir la causa común cuando el repositorio pueda hacerlo;
3. añadir contrato/regresión si corresponde;
4. reintentar sin cambios solo cuando exista evidencia de condición realmente transitoria;
5. documentar bloqueos externos con precisión y continuar trabajo independiente.

---

## 17. Reglas ya acordadas para Condor

Estas reglas provienen de las decisiones tomadas desde el inicio del proyecto y deben considerarse obligatorias hasta que el usuario las cambie explícitamente:

- toda implementación nueva requiere una reserva de Issue mediante `/tomar`; GitHub es el lock central de coordinación multiagente;
- toda reserva usa la rama canónica `trabajo/issue-N` y no vence automáticamente;
- CI debe rechazar PRs sin relación válida `Closes #N` o con archivos solapados con otros PR abiertos;
- el nombre oficial y público del producto es `Condor App`;
- en documentación técnica, GitHub y conversación de desarrollo se usa `Condor` como nombre corto;
- el dominio canónico es `https://www.condorapp.com.co`;
- el repositorio oficial es `pl0n3r/Condor`;
- Condor reutiliza la infraestructura y las prácticas maduras de BRVTAL cuando tengan sentido;
- no se copia automáticamente lógica de negocio, módulos, rutas, esquema de datos ni deuda histórica de BRVTAL;
- GitHub y todo lo controlable por el proyecto se escribe en español siempre que sea técnicamente viable;
- el producto está pensado inicialmente para Colombia y usa `es-CO` y COP como defaults;
- el roadmap canónico vive en GitHub Issue #1;
- el roadmap registra de forma acumulativa todo trabajo planeado, realizado, pendiente y bloqueado;
- la convención visual es exactamente: ✅ ~~completado~~, 🚧 pendiente/en curso, ⛔ bloqueado;
- los elementos completados se conservan tachados y no se eliminan;
- el roadmap no se compacta ni elimina historial antes de v1.0.0; si debe dividirse por tamaño, se crea una continuación sin alterar el histórico ya registrado;
- el roadmap no es el archivo de especificaciones ni un repositorio de políticas; contiene únicamente trabajo/progreso e historial de ejecución;
- `ESPECIFICACIONES.md` contiene decisiones durables, reglas funcionales y detalle arquitectónico;
- el roadmap debe poder ser leído por una socia o stakeholder para entender el avance en tiempo real sin necesitar contexto técnico;
- `ROADMAP.md` es solo un punto de entrada al Issue #1 y no mantiene una copia paralela del progreso;
- `AGENTES.md` es el protocolo operativo canónico y hereda la estructura/reglas aplicables de BRVTAL;
- toda PR relevante debe reflejar en el roadmap cualquier cambio real de estado antes o inmediatamente después del cierre de la entrega;
- el roadmap funciona como un log detallado acumulativo desde el inicio del proyecto hasta, como mínimo, la primera v1.0.0 madura;
- nunca declarar VALIDADO EN PRODUCCIÓN únicamente porque CI esté verde;
- Condor usa versión humana de producto por cada deploy;
- el primer deploy será `0.1.0`;
- el incremento normal por deploy es patch (`0.1.1`, `0.1.2`, ...);
- todos los títulos visibles de GitHub usan la versión objetivo al final con formato exacto `(V X.Y.Z)`;
- los saltos minor son hitos deliberados y `1.0.0` requiere decisión explícita del usuario;
- cuando exista la aplicación, `config/version.php` será la fuente canónica de versión y cualquier metadata equivalente deberá mantener paridad;
- el README usa la misma estrategia de snapshot por deploy que BRVTAL;
- el README debe mostrar siempre y por separado la versión objetivo y la versión realmente desplegada; tras el primer deploy, la señal visible será por ejemplo `v0.1.0`, pero nunca se inferirá desde CI;
- `GLOSARIO.md` debe mantenerse actualizado con los términos técnicos relevantes que aparezcan en superficies visibles para socios, usando lenguaje de negocio y ejemplos de Condor cuando ayuden.

---

## 18. Mantenimiento de este archivo

Actualizar `AGENTES.md` cuando cambie de forma durable:

- el flujo de desarrollo;
- la arquitectura operativa;
- los gates obligatorios;
- reglas de seguridad;
- estrategia de testing;
- estrategia de despliegue;
- responsabilidades entre archivos/fuentes de verdad.

No convertir `AGENTES.md` en un roadmap ni en un historial de releases.

Cuando una decisión o implementación introduzca terminología técnica relevante para seguimiento de negocio, revisar si `GLOSARIO.md` necesita actualización en la misma PR.

**Regla final:** un agente nuevo debe poder leer este archivo, revisar el repositorio y el Issue #1, y continuar Condor sin necesitar el chat anterior.
