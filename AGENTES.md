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
4. La reserva exitosa crea de forma atómica la rama canónica `trabajo/issue-N`, cambia el Issue a `estado: reservado` y publica un **ID de reserva UUID**.
5. La sesión que ejecutó `/tomar` debe conservar ese UUID y usarlo en el PR como `Reserva: <UUID>`.
6. Si otra sesión intenta reservar el mismo Issue, la creación atómica de la rama actúa como lock: solo una puede ganar.
7. Si la reserva es rechazada, **no trabajar esa tarea**; elegir otro Issue disponible.

### Propiedad de la reserva

- una reserva no vence automáticamente: el sistema es **fail-closed**;
- compartir la misma cuenta de GitHub **no** autoriza a dos sesiones a trabajar el mismo Issue;
- cada toma genera un ID de reserva UUID distinto, incluso bajo el mismo login;
- una sesión solo puede continuar una reserva si conoce el UUID de su propia toma o recibió una transferencia explícita;
- nunca adoptar el UUID visible de otra sesión solo porque se comparte la misma cuenta;
- nunca modificar `trabajo/issue-N` si pertenece a otra sesión/agente;
- nunca crear ramas alternativas para saltarse una reserva existente;
- para liberar normalmente, comentar `/liberar <UUID>`;
- para transferir el trabajo a otra sesión de la misma cuenta, comentar `/transferir <UUID>`; el workflow genera un UUID nuevo e invalida el anterior;
- para transferir entre cuentas distintas, liberar y permitir que la nueva cuenta ejecute `/tomar`;
- el dueño del repositorio puede resolver una reserva huérfana con `/liberar-forzado`.

### Pull Requests y colisiones

- después del primer commit lógico, abrir el PR pronto para hacer visible el alcance en curso;
- el PR debe salir de `trabajo/issue-N`, incluir `Closes #N` y declarar `Reserva: <UUID>` en el cuerpo;
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
| Backend objetivo inicial | PHP 8.5 en producción Hostinger |
| Base de datos objetivo inicial | MariaDB / MySQL-compatible |
| Frontend objetivo inicial | HTML + CSS + JavaScript con dependencias contenidas |
| Navegador / E2E | Playwright |
| CI | GitHub Actions — activo |
| Calidad | SonarQube Cloud + CodeRabbit — activos; ampliación de gates en progreso |
| Deploy objetivo | GitHub `main` → Hostinger |
| Versión inicial de producto | `0.1.0` |

Estos valores son una base, no una obligación eterna. Si las necesidades reales del producto justifican un cambio de arquitectura o plataforma, documentarlo primero en `ESPECIFICACIONES.md` y reflejar el trabajo en el roadmap.

Mientras Hostinger shared hosting sea la plataforma elegida:

- PHP 8.5 es el runtime objetivo de producción y CI;

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

## 8. Pruebas, calidad y regresiones

La estrategia es **behavior-first** y proporcional al riesgo.

- Preferir contratos ejecutables sobre búsquedas de strings en código.
- Unit/contract tests para invariantes puros.
- Integration tests para persistencia, API, autorización y límites entre capas.
- Playwright para flujos reales de navegador.
- Cuando exista autenticación, usar usuarios E2E aislados y descartables; nunca credenciales ni datos reales de producción.
- Chromium es la cobertura primaria; WebKit se añade de forma dirigida cuando el riesgo Safari/iOS lo justifique.
- Toda corrección determinista importante debe ganar una regresión automática cuando exista un límite estable y razonable para probarla.
- Un test que solo reproduce implementación interna y no comportamiento observable no sustituye una regresión útil.

### Calidad automática

CI, SonarQube Cloud y CodeRabbit forman un único sistema de evidencia:

- ejecutarlos en paralelo cuando sea seguro;
- CodeRabbit y Sonar deben revisar el **head estable** previsto para merge, evitando tormentas de commits que reinicien análisis sin necesidad;
- verificar cada finding contra el código actual; corregir los válidos en la causa raíz y descartar con motivo los que ya no apliquen;
- si una corrección cambia el head, los resultados anteriores no prueban ese nuevo head: revalidar los gates afectados;
- no declarar un gate aprobado mientras siga procesando;
- no fusionar con threads válidos sin resolver;
- un check `success` por haber sido omitido solo es aceptable cuando la clasificación de cambios demuestra que ese gate no aplica.

---

## 9. CI como sistema operativo de entrega

El CI de Condor debe ser **rápido, selectivo, fail-safe, autoauditable y autocurable solo cuando sea seguro**.

### 9.1. Topología e invariantes

1. **Preflight obligatorio y corto**: valida metadata esencial y clasifica el cambio.
2. **Selección por exclusión segura**: solo se omite un gate cuando el cambio pertenece a una categoría explícitamente inocua para ese gate.
3. **Fail-safe**: ruta desconocida, entrypoint nuevo, configuración crítica, dependencias, scripts de CI, workflows o cambios del propio clasificador implican validación completa.
4. **Paralelización**: gates independientes arrancan en paralelo; no crear cadenas seriales por comodidad.
5. **Timeouts explícitos**: todo job debe tener presupuesto finito.
6. **Check agregado estable**: `Validar` permanece como contrato de branch protection y consolida jobs ejecutados u omitidos legítimamente.
7. **Entorno limpio y reproducible**: dependencias se resuelven desde lockfiles; bases de CI son descartables; secretos son efímeros.
8. **Mínimo privilegio**: permisos de Actions se declaran por workflow/job; acciones externas se fijan a SHA; no usar `pull_request_target` para ejecutar código no confiable.
9. **Caché conservadora**: cachear descargas/dependencias cuando acelere sin ocultar estado; no cachear bases, secretos ni estado generado que pueda esconder una regresión.
10. **Evidencia útil**: conservar logs/artefactos de fallo cuando aporten diagnóstico; evitar artefactos de éxito sin valor.
11. **Exactitud de identidad**: cada resultado pertenece a un SHA concreto. PR head, squash resultante en `main` y producción son estados distintos.
12. **Telemetría comparable**: medir duración/throughput entre perfiles de ejecución equivalentes; no comparar un PR documental con un full-stack como si fueran el mismo workload.

### 9.2. Política de autocuración y retry

Un retry es válido únicamente cuando **la misma entrada puede producir éxito sin cambiar código ni estado funcional** y la operación es idempotente.

Se pueden reintentar de forma acotada:

- descargas de dependencias;
- registry/package manager;
- instalación de artefactos externos;
- HTTP externos con timeout, 408/425/429 o 5xx cuando la operación sea segura;
- otras operaciones explícitamente clasificadas como transitorias.

Todo retry debe tener:

- máximo de intentos;
- backoff;
- presupuesto total de tiempo;
- mensaje que identifique la operación;
- retorno del error original si agota el presupuesto.

**Nunca autocurar mediante retry ciego**:

- assertions o tests fallidos;
- lint, typecheck o análisis estático;
- errores de sintaxis;
- migraciones/schema mismatch;
- fallos de autorización/permisos;
- contratos/invariantes rotos;
- errores de configuración deterministas;
- findings de seguridad/calidad;
- un smoke que demuestra versión/SHA/estado funcional incorrecto.

Ante un fallo determinista:

**diagnosticar → corregir causa raíz → añadir/ajustar regresión → ejecutar prueba dirigida → revalidar el gate afectado → continuar**.

Si un fallo aparentemente flaky se origina en el repositorio, la prioridad es eliminar la fuente de flakiness; no normalizar reruns hasta obtener verde.

### 9.3. Uso eficiente del tiempo de CI

- Mientras CI/Sonar/CodeRabbit procesan un head estable, avanzar análisis de solo lectura o trabajo independiente que no cambie ese head ni colisione con otras reservas.
- No hacer commits cosméticos mientras una revisión automática útil está en curso.
- Si aparece un finding válido, agrupar correcciones relacionadas antes de volver a disparar gates.
- Si `main` cambia antes del merge, recontrastar la PR contra el nuevo `main`; no asumir que un head verde sobre una base antigua sigue siendo integrable.
- Un workflow detenido por dependencia externa no bloquea investigación, documentación de causa o trabajo independiente compatible.

No crear workflows duplicados si un gate pertenece naturalmente al CI canónico.

---

## 10. Flujo CI-first obligatorio de entrega

Para código o configuración, ejecutar este bucle completo:

### A. Preparar

1. leer `AGENTES.md`, obtener el SHA exacto de `main`, revisar PRs/gates y Roadmap #1;
2. elegir un Issue disponible, reservarlo y conservar su UUID;
3. comprobar colisiones con trabajo abierto antes de editar archivos globales;
4. partir de la rama canónica creada desde el `main` vigente.

### B. Implementar

5. implementar el cambio mínimo completo;
6. añadir/ajustar regresiones según riesgo;
7. ejecutar primero las pruebas dirigidas que puedan fallar rápido;
8. agrupar cambios en commits lógicos y abrir la PR temprano cuando ya exista un primer bloque coherente.

### C. Estabilizar la PR

9. ejecutar CI, coordinación, Sonar y CodeRabbit en paralelo;
10. clasificar cada fallo como **determinista**, **transitorio externo** o **bloqueo externo**;
11. para deterministas, corregir y revalidar; para transitorios, retry acotado solo si cumple §9.2;
12. estabilizar el head: sin findings válidos pendientes, threads relevantes resueltos y gates aplicables verdes;
13. comprobar nuevamente `main`, mergeability, reserva y head exacto antes de fusionar.

### D. Integrar

14. hacer **squash merge** de forma serial;
15. obtener el SHA exacto resultante de `main`;
16. validar los gates aplicables contra ese SHA; no heredar automáticamente la evidencia del head de PR.

### E. Entregar

17. observar el deploy por separado usando versión + SHA;
18. aplicar la checklist de transición de producción de §16 antes de declarar la release sana;
19. validar comportamiento real solo con evidencia de producción;
20. actualizar Roadmap #1 y, cuando cambie una decisión durable, `ESPECIFICACIONES.md`.

No iniciar una rama dependiente nueva antes de cerrar la validación de `main` del bloque anterior. El análisis y trabajo independiente sin colisión sí pueden adelantarse.

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

## 13. Roadmap canónico y registro macro de avance

El roadmap activo es **[GitHub Issue #1](https://github.com/pl0n3r/Condor/issues/1)** y se conserva de forma acumulativa hasta **V 1.0.0**.

**Regla permanente de preservación y orden:** el Issue #1 debe conservar la historia completa desde la definición inicial de Condor, organizada cronológicamente y por fases. Nunca se reemplaza por una versión resumida que elimine contexto previo. Los avances nuevos se agregan manteniendo la estructura existente; los hitos completados permanecen visibles y tachados. Solo las microactualizaciones operativas pueden resumirse, nunca el historial macro del proyecto.

### Dos capas del mismo registro

- **Cuerpo del Issue #1:** plan acumulativo, fases, tareas, estados y ruta hacia V 1.0.0. Debe mantenerse lógico, escaneable y ordenado.
- **Comentarios del Issue #1:** historial **macro** de hitos relevantes: qué bloque importante se implementó, qué decisión cambió el producto y cuál fue el resultado.
- El Roadmap **no es un log minuto a minuto**. Commits individuales, reintentos, gates parciales, findings menores y correcciones intermedias viven en el Issue, PR o check correspondiente.
- Si varias acciones pertenecen a la misma iteración, consolidarlas en un único comentario de hito cuando exista un resultado sustancial o cambie materialmente el estado.
- Las microactualizaciones operativas pueden consolidarse para reducir ruido, pero **nunca** se eliminan o compactan hitos macro, fases, decisiones de producto, entregas, incidentes o contexto histórico relevante.

### Qué merece una noticia en el Roadmap

Publicar un comentario únicamente cuando ocurra un avance de nivel hito:

1. se completa o entrega un slice, módulo o capacidad relevante;
2. se toma una decisión funcional, técnica o arquitectónica durable;
3. se resuelve un incidente importante de producción o un bloqueo mayor;
4. una versión alcanza un estado significativo: validada en código, fusionada, desplegada o validada en producción;
5. cambia de forma importante el alcance, prioridad o dirección de una iteración.

**No publicar comentarios separados** por cada commit, PR abierto, reintento, check individual, finding menor, corrección pequeña o paso rutinario del pipeline.

### Formato de los hitos

Usar lenguaje entendible para una socia no técnica y resumir la iteración completa en pocos puntos:

```md
### Hito macro — <resultado o decisión>
- **Qué se logró:** ...
- **Alcance:** ...
- **Evidencia clave:** Issue/PR/SHA/versión cuando aporte contexto.
- **Estado:** VALIDADO EN CÓDIGO | MERGED | DESPLEGADO | VALIDADO EN PRODUCCIÓN | BLOQUEADO.
- **Siguiente:** solo el próximo bloque relevante.
```

Reglas:

- priorizar resultado e impacto sobre detalle operacional;
- conservar suficiente contexto para entender el proyecto meses después sin leer todo el PR;
- distinguir validación en código, merge, deploy y validación real de producción;
- nunca marcar `VALIDADO EN PRODUCCIÓN` sin evidencia real;
- no publicar mensajes vacíos del tipo “sigo”, “adelante” o “trabajando”;
- un finding o fallo solo llega al Roadmap si cambia materialmente la iteración, explica un incidente relevante o altera su resultado;
- el detalle técnico fino pertenece al Issue específico, PR, checks o `ESPECIFICACIONES.md`;
- el cuerpo del roadmap se actualiza cuando cambia el plan o estado acumulativo de una tarea/fase, **sin borrar ni reescribir el histórico anterior para limpiar la vista**;
- mantener siempre el orden lógico: origen/definición → fases → implementación → versiones/incidentes → iteración actual → próximos bloques.

Convención visual del cuerpo:

- ✅ ~~Completado y validado por los gates requeridos~~
- 🚧 Pendiente / en curso
- ⛔ Bloqueado / dependencia externa

Reglas generales:

- todo trabajo planeado relevante debe aparecer en el cuerpo del roadmap;
- todo trabajo completado relevante permanece visible y tachado;
- registrar PR y versión cuando exista una release asignada;
- una instrucción explícita del usuario puede repriorizar el roadmap;
- Issues específicos contienen criterios de aceptación; el roadmap contiene orden, estado e hitos macro;
- después de un merge relevante, el cuerpo debe reflejar la realidad, no el plan anterior;
- hasta alcanzar una **V 1.0.0 madura**, conservar el historial de hitos relevantes sin volver a introducir microactualizaciones;
- **no crear un segundo Roadmap ni un volumen de continuación**; si el cuerpo se acerca a un límite práctico, reorganizar dentro del mismo Issue #1 y usar sus comentarios para hitos macro adicionales, preservando todo el histórico;
- el roadmap debe conservar una lectura limpia para socios y personas no técnicas;
- las políticas permanentes, manuales y detalle técnico pertenecen a `AGENTES.md`, `ESPECIFICACIONES.md`, Issues o PRs, no al Roadmap.

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

## 14.1. Evolución incremental del frontend

Regla permanente:

- el frontend **no se deja para el final**; debe evolucionar poco a poco junto con cada slice funcional;
- cuando una entrega toque una superficie visible, aprovechar para mejorar de forma proporcional estructura, jerarquía, copy, responsive, accesibilidad y acabado visual;
- evitar rediseños gigantes desconectados del producto: preferir mejoras pequeñas, coherentes y acumulativas;
- mantener consistencia visual entre home público, login y backoffice sin obligar a que compartan exactamente la misma composición;
- el home público de Condor debe proyectar una imagen **corporativa, sobria, premium y fina**, con claridad, aire, tipografía cuidada, jerarquía fuerte, movimiento discreto y ausencia de ruido visual;
- Apple puede usarse como referencia de nivel de acabado, sobriedad y precisión visual, **no como plantilla para copiar ni como fuente de identidad visual**;
- priorizar fondos limpios, blancos/grises/negros controlados, contraste alto, espaciado generoso, tarjetas/bordes discretos y animaciones sutiles cuando aporten;
- evitar estética genérica de SaaS saturada: exceso de gradientes, blobs, ilustraciones stock, iconos innecesarios, demasiados colores o CTAs compitiendo;
- cada mejora visual debe conservar rendimiento, accesibilidad, mobile-first y SSR/SEO de las superficies públicas;
- el contenido editable del frontend/CMS se definirá en una fase posterior; **no acoplar el diseño visual actual a una solución concreta de gestión de contenido**.

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

## 16. Producción, transiciones y operaciones protegidas

Nunca ejecutar automáticamente:

- SQL destructivo en producción;
- resets/seeds de producción;
- borrado irreversible de datos;
- rotación de secretos reales;
- cambios de DNS/infraestructura irreversibles;
- acciones con riesgo de interrupción sin vía segura de rollback;
- migraciones productivas que no estén explícitamente autorizadas.

El despliegue de código, la migración de esquema y la **transición de estado operativo** son operaciones distintas.

### 16.1. Checklist de transición antes de validar producción

Un deploy no se considera sano solo porque el nuevo código está presente. Cuando el cambio toque configuración, roles, esquema, servicios, comandos, rutas o estado persistente, comprobar explícitamente:

1. **Identidad de release** — versión humana y SHA desplegado coinciden con lo esperado.
2. **Esquema** — estado de Doctrine Migrations conocido; no asumir que deploy implica migración.
3. **Transición de datos** — backfills, provisioning, singleton records, roles, flags o conversiones requeridas están completos.
4. **Compatibilidad de estado** — el código nuevo puede leer el estado previo durante la transición o falla de forma segura y accionable.
5. **Container/caché** — cuando cambien servicios, controladores, rutas o comandos, limpiar/warmup de prod y comprobar descubrimiento real.
6. **Configuración externa** — variables de entorno/secrets necesarios existen sin imprimir sus valores.
7. **Superficies críticas** — rutas, comandos y servicios esperados existen en el runtime desplegado.
8. **Rollback** — si cambia persistencia real, evaluar compatibilidad hacia atrás o usar estrategia expand/contract; no crear un punto de no retorno accidental.
9. **Smoke** — comprobar endpoints públicos y, cuando corresponda, flujo autenticado seguro/aislado.
10. **Invariantes** — validar el estado que el código realmente necesita, no solo que las tablas existan.

Ejemplos de invariantes: rol propietario asignado, singleton con referencia válida, tenant/membresía activa, configuración obligatoria presente, comando nuevo descubierto y migración realmente aplicada.

Una transición incompleta debe **bloquear la validación de producción**. Siempre que el diseño lo permita, debe producir un estado seguro y diagnosticable (4xx/5xx controlado con referencia) en vez de un 500 opaco.

### 16.2. Tratamiento de fallos de producción

Ante un fallo:

1. separar **código**, **deploy**, **esquema**, **configuración**, **caché/container** y **estado persistente** como hipótesis independientes;
2. priorizar comprobaciones read-only;
3. confirmar la causa con evidencia antes de ejecutar una mutación;
4. aplicar la mínima corrección autorizada;
5. revalidar la misma superficie real que falló;
6. convertir la causa determinista en regresión, readiness check o regla de CI cuando sea razonable;
7. registrar en el Roadmap solo el incidente/hito macro; el detalle técnico queda en Issue/PR.

Ante un fallo recurrente o de CI:

- corregir la causa común cuando el repositorio pueda hacerlo;
- reintentar sin cambios solo con evidencia de condición transitoria;
- documentar bloqueos externos con precisión;
- continuar trabajo independiente compatible mientras exista.

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
- el roadmap registra de forma acumulativa los hitos, trabajo relevante, pendientes y bloqueos principales;
- la convención visual es exactamente: ✅ ~~completado~~, 🚧 pendiente/en curso, ⛔ bloqueado;
- los elementos completados relevantes se conservan tachados en el cuerpo del roadmap;
- el roadmap se mantiene **ordenado y completo**, no reducido: los comentarios registran hitos macro y el detalle operacional vive en Issues/PRs; nunca se crea un segundo Roadmap para dividir el histórico;
- el roadmap no es el archivo de especificaciones ni un repositorio de políticas; contiene únicamente trabajo/progreso e historial de ejecución;
- `ESPECIFICACIONES.md` contiene decisiones durables, reglas funcionales y detalle arquitectónico;
- el roadmap debe poder ser leído por una socia o stakeholder para entender el avance en tiempo real sin necesitar contexto técnico;
- `ROADMAP.md` es solo un punto de entrada al Issue #1 y no mantiene una copia paralela del progreso;
- `AGENTES.md` es el protocolo operativo canónico y hereda la estructura/reglas aplicables de BRVTAL;
- toda PR relevante debe reflejar en el roadmap cualquier cambio real de estado antes o inmediatamente después del cierre de la entrega;
- el roadmap funciona como un registro macro acumulativo desde el inicio del proyecto hasta, como mínimo, la primera v1.0.0 madura;
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
- **No usar archivos ZIP como mecanismo de entrega, respaldo, transferencia de código o handoff del proyecto.** Todo cambio de código/documentación debe quedar directamente versionado en GitHub mediante commits, ramas, PRs y merges; no enviar paquetes ZIP al usuario ni usar ZIP como sustituto del repositorio. Los artefactos internos automáticos de GitHub Actions solo pueden existir como evidencia técnica efímera cuando una herramienta los genere de forma inevitable, nunca como fuente de verdad ni como canal de entrega.

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

**Regla final:** un agente nuevo debe poder leer este archivo, revisar el repositorio y el Issue #1, y continuar Condor sin necesitar el chat anterior. El Issue #1 debe permanecer como el único Roadmap, cronológico, acumulativo y completo desde la definición inicial del proyecto.
