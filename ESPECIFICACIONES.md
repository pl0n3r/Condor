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

La plataforma inicial está condicionada por **Hostinger shared hosting**, pero la aplicación debe permanecer portable para una futura migración a AWS.

Stack confirmado:

- GitHub;
- GitHub Actions;
- SonarCloud;
- CodeRabbit;
- Hostinger shared hosting;
- PHP 8.5 como runtime de backend;
- Symfony 7.4 LTS como framework backend;
- MariaDB como base de datos;
- arquitectura de **monolito modular**;
- React + TypeScript para el frontend administrativo;
- Vite para compilar React a assets estáticos;
- Twig/Symfony para render server-side de superficies públicas y SEO;
- Node.js permitido como herramienta de desarrollo/build, no requerido como runtime de producción;
- Playwright;
- despliegue desde `main` hacia Hostinger;
- configuración por entorno y ausencia de dependencias propietarias del hosting;
- separación entre validación de código, observación del despliegue y validación de producción.

La arquitectura debe permitir que una futura migración a AWS sea principalmente una evolución de infraestructura. No se adoptarán microservicios inicialmente; los módulos internos deben mantener fronteras suficientemente claras para extraer componentes en el futuro solo si una necesidad real de escala, carga u organización lo justifica.

## 5. Definición funcional del producto

### 5.1 Problema y mercado confirmados

Condor App será un **SaaS B2B para empresas colombianas**.

El problema general que el producto busca atacar es la baja digitalización de empresas que todavía no cuentan con herramientas suficientes o integradas para operar partes importantes del negocio.

Carencias identificadas hasta ahora:

- no tener presencia web;
- no tener e-commerce;
- no contar con una administración centralizada del e-commerce;
- no manejar inventarios digitalmente;
- no tener una administración flexible de precios;
- no poder manejar condiciones comerciales para mayoristas;
- no poder aplicar descuentos por cantidad.

El tamaño exacto de empresa objetivo todavía no está cerrado. El foco en pymes es una **hipótesis**, no una decisión final.

### 5.2 Primer frente de producto confirmado

El primer frente que Condor App debe definir y atacar está compuesto por:

1. **Presencia web** de la empresa.
2. **E-commerce**.
3. **Administración del e-commerce**.
4. **Manejo de inventarios**.
5. **Administración de precios**.
6. **Condiciones o precios para mayoristas**.
7. **Descuentos por cantidad**.

La intención no es construir únicamente una tienda online aislada. La visión confirmada es que Condor App pueda crecer progresivamente hasta ayudar a digitalizar distintas partes operativas de una empresa.

El punto de entrada inicial será el frente comercial/digital: presencia web + comercio electrónico + operación de catálogo/precios/inventario.

### 5.3 Hipótesis y decisiones todavía abiertas

No considerar como requisito cerrado hasta que producto lo confirme:

- foco específico en pymes;
- alcance exacto de la presencia web;
- uso de plantillas, constructor visual o servicio administrado;
- alcance completo de pedidos/ventas;
- proveedores concretos de pagos, envíos y facturación;
- alcance de CRM;
- precios, límites y empaquetado exacto de los planes/módulos del SaaS;
- integraciones externas concretas.

Ya están confirmados como principios o capacidades funcionales: multiempresa/multi-tenant, núcleo Empresa + Sede + Usuario, catálogo producto → variantes → precios → inventario configurable por fuente, Cliente como entidad comercial separada del Usuario, suite con mensualidad base + módulos adicionales, primera versión sin integraciones externas obligatorias y reglas iniciales de precios, roles, inventario y ventas documentadas en las decisiones durables.

### 5.4 Definiciones pendientes de producto

Condor no usa un MVP rígido como frontera artificial. La construcción avanza por bloques incrementales coherentes.

Pendientes principales:

- precisar el perfil de empresa objetivo inicial;
- formular problema principal y propuesta de valor en una frase;
- mapear flujos críticos de extremo a extremo;
- terminar de definir límites de los módulos iniciales;
- completar reglas de ventas/pedidos, descuentos, impuestos y efectos sobre inventario;
- definir el nivel de integración entre sitio público, e-commerce y backoffice;
- definir métricas de éxito iniciales;
- seguir separando qué reglas son configurables por empresa y qué invariantes deben permanecer fijos para proteger coherencia, seguridad y trazabilidad.

### 5.5 Regla de esta fase

Durante esta fase la prioridad es **definir el producto**. Una hipótesis de producto no debe convertirse automáticamente en arquitectura, esquema de datos o código hasta que su alcance funcional quede acordado.


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

- todo trabajo de implementación parte de un Issue abierto marcado `estado: disponible` o de la recuperación válida de una reserva inactiva existente;
- para trabajo nuevo, el comando `/tomar` intenta crear de forma atómica `trabajo/issue-N`; la creación de esa rama funciona como lock distribuido;
- solo una reserva puede existir para un Issue;
- una reserva con actividad verificable reciente permanece protegida y no puede ser asumida por otra sesión;
- la ventana inicial de actividad es de **45 minutos**; cuentan los commits de la rama, la actividad del PR existente y los comentarios humanos útiles del Issue, pero no los comandos de coordinación por sí solos;
- después de al menos 45 minutos sin actividad verificable, `/tomar` puede recuperar la reserva conservando la rama canónica y únicamente el PR abierto asociado a esa rama; la recuperación genera un UUID nuevo, reemplaza la metadata `Reserva: <UUID>` del PR e invalida el UUID anterior;
- la recuperación es fail-closed: si no existe evidencia temporal suficiente, el coordinador conserva la reserva vigente; un Issue bloqueado nunca se recupera automáticamente;
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

### D-016 — Gate E2E rápido con Chrome estable del runner

La telemetría identificó a la preparación del navegador como el cuello de botella inicial del CI. Se evaluó cachear los binarios Playwright: el cache hit fue real, pero redujo el job solo de **35 s a 33 s** mientras restauraba aproximadamente **271 MB**, por lo que esa estrategia fue descartada.

Condor usa para el gate E2E rápido el Chrome estable preinstalado en el runner GitHub `ubuntu-24.04`, controlado desde Playwright mediante `channel: 'chrome'`.

Reglas:

- el motor Chromium sigue siendo la cobertura primaria;
- `@playwright/test` permanece fijado por lockfile;
- `npm ci` se ejecuta en cada run;
- no descargar ni cachear Chromium para el gate rápido cuando el runner ya provee Chrome;
- instalar únicamente el helper `ffmpeg` de Playwright para conservar video/diagnóstico en fallos;
- registrar la versión real de Chrome en el Job Summary;
- fijar explícitamente el sistema del runner en `ubuntu-24.04`;
- WebKit sigue disponible para ejecución dirigida cuando el riesgo lo justifique;
- si una futura incompatibilidad exige una revisión exacta de Chromium, se puede usar el browser Playwright fijado de forma dirigida;
- toda optimización se conserva solo si la medición demuestra una mejora material sin perder cobertura.

Evidencia inicial: el enfoque final redujo Playwright de **37 s a 12 s** y el CI de PR de aproximadamente **50 s a 31 s**. Estos datos deben confirmarse nuevamente sobre el SHA exacto de `main`.


### D-017 — Entrada inicial por digitalización comercial

El primer frente de Condor App será la digitalización comercial de empresas colombianas con baja digitalización: presencia web, e-commerce, administración del e-commerce, inventario, precios, condiciones para mayoristas y descuentos por cantidad.

Esta decisión define dirección de producto, no todavía arquitectura ni alcance completo del MVP.


### D-018 — Simple por defecto y altamente configurable por empresa

La configurabilidad es un principio transversal de Condor App.

Reglas:

- el producto debe funcionar correctamente con valores por defecto sensatos, sin obligar a una empresa a configurar todo antes de operar;
- cuando una regla cambie razonablemente entre empresas, debe preferirse una opción o parámetro por tenant antes que una regla rígida quemada en código;
- la configuración debe responder a variaciones reales de negocio y no convertirse en una capa abstracta de sobrearquitectura;
- la experiencia administrativa toma **WooCommerce como referencia de facilidad y configurabilidad**, sin copiar su interfaz, código ni arquitectura;
- la complejidad avanzada debe aparecer únicamente cuando el caso de uso la necesite;
- Empresa, Sede y Usuario forman parte del núcleo mínimo transversal;
- los roles parten de plantillas base, pero cada empresa puede editarlos y crear roles adicionales;
- la primera versión de autorización se mantiene deliberadamente simple: módulos + permisos CRUD, con alcance por sede cuando corresponda;
- los permisos especializados se incorporarán solo cuando exista un caso de uso real que los justifique;
- la flexibilidad nunca puede romper invariantes necesarios para integridad, seguridad o trazabilidad.

### D-019 — Reglas iniciales configurables de ventas y precios manuales

Las primeras reglas acordadas para ventas/pedidos deben preservar flexibilidad sin perder auditoría.

Reglas:

- permitir una venta o pedido sin cliente registrado es configurable por empresa;
- modificar manualmente el precio de una línea de venta puede habilitarse o restringirse según configuración y permisos;
- toda modificación manual de precio debe conservar trazabilidad de quién realizó el cambio, cuándo ocurrió y por qué;
- exigir un motivo al modificar el precio es una opción configurable simple de tipo sí/no;
- el valor por defecto propuesto para exigir motivo es **sí**;
- el motivo puede comenzar como texto libre; una lista administrable de motivos se considera una extensión futura, no una necesidad inicial;
- los descuentos deben admitir evolución flexible a reglas por producto y/o por pedido, sin introducir complejidad antes de que el dominio lo requiera.


### D-020 — Configuración progresiva sin proliferación de pantallas

La configurabilidad transversal no debe trasladar toda la complejidad del backend a la interfaz.

Reglas:

- Condor debe funcionar con valores por defecto útiles y permitir operar lo básico sin pasar primero por pantallas de configuración;
- la UI debe usar **divulgación progresiva**: mostrar primero opciones comunes y revelar configuración avanzada solo cuando el caso de uso la necesite;
- evitar pantallas infinitas de switches, formularios duplicados o parámetros repetidos por módulo;
- agrupar opciones relacionadas y reutilizar defaults, herencia y plantillas cuando sea posible;
- una capacidad puede existir en el backend y permanecer oculta/inactiva en la experiencia inicial hasta que aporte valor real;
- la meta es máxima flexibilidad funcional con mínima carga cognitiva para el usuario.

### D-021 — Estados separados para pedido, pago y cumplimiento

Condor trata el ciclo comercial como dimensiones relacionadas pero independientes.

Reglas:

- **pedido**, **pago** y **cumplimiento/entrega** no comparten un único estado global;
- cada dimensión mantiene estados semánticos internos mínimos y estables para que el sistema pueda aplicar reglas coherentes;
- cada empresa puede personalizar nombres visibles y añadir etapas intermedias sin perder el significado interno;
- todo cambio de estado relevante debe conservar trazabilidad de quién lo ejecutó, cuándo ocurrió y la transición realizada;
- los métodos de pago son configurables por empresa, con defaults activables/desactivables y capacidad de añadir otros;
- reglas como pagos parciales o saldo pendiente se modelan como capacidades configurables, no como obligación universal;
- el cumplimiento del pedido cubre como defaults **envío**, **recogida** y **sin entrega física**;
- el modelo debe poder evolucionar a cumplimientos parciales o múltiples sin obligar a exponer esa complejidad desde el inicio.

### D-022 — Motor transversal de workflows personalizados

Condor debe poder adaptar procesos operativos mediante workflows personalizados sin implementar un motor distinto para cada módulo.

Reglas:

- existirá un **motor común y transversal de workflows** reutilizable por las diferentes áreas del producto;
- los workflows son opcionales: una empresa puede operar únicamente con flujos predefinidos/default;
- la primera etapa prioriza workflows internos y simples;
- el modelo conceptual debe poder evolucionar hacia **disparadores + condiciones + acciones**, con trazabilidad de cada ejecución;
- la arquitectura debe separar el motor del detalle de cada módulo mediante contratos/interfaces estables;
- integraciones externas, webhooks y acciones hacia terceros quedan fuera del alcance inicial;
- aun así, el diseño debe permitir añadir adaptadores externos en el futuro sin rehacer el núcleo del motor;
- la personalización de workflows debe respetar los invariantes del dominio, permisos, auditoría y aislamiento por tenant.


### D-023 — Stack técnico inicial portable de Hostinger a AWS

Condor se implementará inicialmente sobre las capacidades reales disponibles en el hosting compartido de Hostinger, evitando diseñar contra un runtime que no exista en producción.

Decisiones:

- backend en **PHP 8.5 + Symfony 7.4 LTS**;
- persistencia en **MariaDB**;
- arquitectura de **monolito modular**, con límites internos claros entre dominios;
- administración en **React + TypeScript**;
- React se compila con **Vite** a assets estáticos servidos por la misma aplicación;
- las superficies públicas y sensibles a SEO usarán **Twig/Symfony server-side rendering** como base;
- React se incorpora en esas superficies solo donde aporte interacción real;
- Node.js puede formar parte del toolchain de desarrollo/build, pero no es una dependencia del runtime de producción;
- no se usarán microservicios en la primera arquitectura;
- se evitarán APIs, servicios o convenciones propietarias de Hostinger cuando exista una alternativa estándar razonable;
- configuración, secretos y endpoints deben resolverse por entorno;
- el acceso a datos y las migraciones deben diseñarse para mantener MariaDB portable hacia infraestructura administrada en AWS;
- una eventual migración a AWS debe cambiar principalmente la infraestructura, no exigir una reescritura funcional del producto.

Las decisiones de ORM, migraciones, estructura modular, frontera Twig/React y contrato REST inicial ya quedaron resueltas en D-024. Permanecen pendientes el detalle fino del pipeline de despliegue y la topología futura en AWS cuando la escala real la justifique.

Esta decisión **sustituye** las propuestas conversacionales previas de NestJS/Node como runtime backend, Vue y Next.js. Esas opciones fueron consideradas antes de confirmar las restricciones reales del hosting compartido y ya no representan el stack objetivo de Condor.


### D-024 — Baseline técnico de arquitectura para el arranque

Condor adopta las siguientes decisiones técnicas como baseline del monolito modular inicial:

1. **Persistencia y migraciones**
   - Doctrine ORM como ORM inicial.
   - Doctrine Migrations como mecanismo canónico para cambios de esquema.
   - Todo cambio de estructura de base de datos debe quedar versionado mediante migración.
   - Los identificadores de dominio deben ser opacos y no depender de secuencias visibles; la elección exacta UUID vs ULID se cerrará junto con el modelo de datos inicial.

2. **Configuración por entorno**
   - separar desarrollo, pruebas y producción;
   - secretos fuera del repositorio;
   - variables de entorno como mecanismo principal;
   - evitar dependencias propietarias de Hostinger cuando exista una alternativa estándar razonable.

3. **Frontend y build**
   - React + TypeScript para administración;
   - Vite compila los assets;
   - Node.js pertenece al toolchain de desarrollo/CI/build y no es runtime de producción;
   - Symfony/hosting sirve los assets generados.

4. **Twig y React**
   - Twig/Symfony es la base de superficies públicas y sensibles a SEO;
   - React se usa para el backoffice y componentes interactivos que realmente lo necesiten;
   - no se adopta una SPA global por defecto.

5. **API interna**
   - REST es el contrato inicial;
   - los contratos estables/públicos deben poder versionarse;
   - los errores siguen un formato consistente;
   - la validación server-side es la autoridad;
   - frontend y backend se comunican mediante contratos explícitos.

6. **Estructura modular**
   - monolito modular organizado por dominios;
   - cada módulo encapsula entidades, repositorios, servicios de aplicación y adaptadores/controladores;
   - la coordinación entre módulos se hace mediante servicios de aplicación y, cuando reduzca acoplamiento real, eventos internos simples;
   - no se requieren colas externas inicialmente.

7. **Archivos y media**
   - el almacenamiento de archivos se accede mediante una abstracción;
   - primera etapa: almacenamiento compatible con Hostinger;
   - futuro: adaptador S3/object storage sin modificar el dominio;
   - MariaDB sigue siendo persistencia relacional y no se usa como sustituto de object storage.

8. **Autenticación y sesiones**
   - autenticación web basada inicialmente en sesión segura;
   - cookies seguras, protección CSRF y expiración controlada;
   - recuperación de acceso segura;
   - tokens/API keys solo cuando exista un cliente o integración que los necesite.

9. **RBAC**
   - roles configurables por empresa;
   - permisos CRUD por módulo como baseline;
   - alcance por sede cuando corresponda;
   - permisos acumulativos para usuarios con varios roles;
   - permisos especiales se añaden únicamente cuando exista un caso de uso real.

10. **Auditoría**
    - auditoría transversal y estructurada desde el inicio;
    - registrar actor, acción, entidad, fecha/hora y contexto relevante;
    - distinguir auditoría de negocio de logging técnico;
    - conservar trazabilidad de operaciones administrativas y cambios sensibles.

### D-025 — Resolución de tenant por ruta y dominio personalizado

Cada empresa debe poder exponer su experiencia pública mediante una identidad web propia sin duplicar la aplicación.

Reglas:

- cada tenant tiene un **slug** único y estable;
- Condor puede resolver una empresa por una ruta canónica bajo el dominio de la plataforma, por ejemplo `https://www.condorapp.com.co/empresa-x`;
- un dominio personalizado del cliente puede mapearse al mismo tenant y servir el frontend directamente bajo ese dominio, sin redirección visible hacia Condor;
- la resolución del tenant debe centralizarse en un `TenantResolver` o abstracción equivalente;
- el resolver puede identificar tenant por **host/dominio** y por **slug/ruta** según el canal de entrada;
- los dominios asociados a un tenant se modelan como datos propios, no como configuración quemada en código;
- el diseño debe permitir en el futuro subdominios y múltiples dominios por tenant sin obligar a exponer esa complejidad en la primera versión;
- resolver un tenant nunca sustituye ni relaja el aislamiento de datos: toda consulta y mutación debe seguir validando el tenant activo;
- la resolución por dominio/ruta debe permanecer desacoplada del proveedor de hosting para conservar portabilidad a AWS.


### D-026 — Modelo comercial base y alcance de la primera versión

Condor tendrá un modelo comercial modular sin obligar a resolver desde el inicio todas las integraciones externas.

Reglas:

- el modelo comercial parte de una **mensualidad base por empresa/tenant**;
- módulos o capacidades adicionales pueden contratarse o activarse aparte;
- los precios, límites, nombres de planes y reglas exactas de upgrade/downgrade permanecen pendientes;
- la primera versión puede operar sin pasarela de pago integrada, sin facturación electrónica integrada y sin integraciones externas obligatorias;
- los pedidos pueden existir con registro de pago/manualidad operativa cuando corresponda;
- pagos, envíos, facturación y otras integraciones deben conectarse más adelante mediante adaptadores/contratos, sin rehacer el dominio;
- la ausencia inicial de una integración no debe bloquear el modelo de datos necesario para incorporarla después.

### D-027 — Canales, sedes, inventario y precio efectivo

Condor debe soportar empresas pequeñas de una sola sede y empresas con múltiples sedes sin obligar a usar complejidad innecesaria.

Reglas:

- una empresa puede tener múltiples sedes desde el inicio;
- la primera sede creada se convierte automáticamente en la sede predeterminada;
- la sede predeterminada puede cambiarse posteriormente;
- usuarios internos pueden operar en varias sedes;
- los roles/permisos pueden variar por sede;
- las transferencias entre sedes generan movimientos de inventario auditables;
- cada canal comercial, incluido el e-commerce, selecciona una **fuente de inventario efectiva**;
- por defecto esa fuente puede ser una sede;
- opcionalmente un canal puede usar un stock propio independiente de las sedes;
- por tanto, la ubicación canónica del stock evoluciona de la regla más estrecha “variante + sede” a **variante + fuente de inventario**; una sede es la fuente predeterminada y un stock de canal es una fuente opcional;
- un pedido consume/reserva inventario de una sola fuente efectiva y no se divide automáticamente entre sedes;
- si la fuente configurada está agotada, el producto se muestra agotado aunque exista stock en otra sede;
- no existe fallback automático hacia otra sede;
- cambiar la fuente configurada o reponerla debe reflejarse en la disponibilidad del canal;
- vender sin stock/backorder es configurable por producto y queda **desactivado por defecto**;
- cada canal puede seleccionar su lista de precios predeterminada;
- las reglas de precio/descuento deben resolver un único precio efectivo mediante una precedencia determinista y auditable.

### D-028 — Clientes, catálogo, servicios, personal y entidades legales

El modelo funcional debe distinguir conceptos que pueden coincidir en empresas pequeñas, pero no son equivalentes.

Reglas:

- la compra con cuenta obligatoria o como invitado es configurable por empresa/canal;
- un cliente tiene una sola **categoría comercial principal efectiva** para evitar ambigüedad de precios;
- la categoría comercial puede influir en lista de precios, descuentos y condiciones comerciales;
- las categorías comerciales son configurables por empresa;
- Condor soporta productos físicos y servicios;
- el primer flujo funcional se concentra en productos físicos;
- un servicio puede venderse o cotizarse sin agenda/calendario en la primera etapa;
- agenda/reservas se incorporan únicamente cuando exista un caso real;
- un usuario/empleado interno puede operar en varias sedes;
- un usuario puede tener roles distintos según la sede;
- la autorización efectiva debe considerar tenant + usuario + sede + roles;
- una cuenta/tenant de Condor puede contener **múltiples razones sociales o entidades legales**;
- tenant/cuenta y razón social son entidades diferentes;
- cada entidad legal puede mantener sus propios datos fiscales/legales;
- los módulos que lo necesiten deben poder asociar una operación a una entidad legal concreta;
- una empresa que solo tenga una razón social no debe enfrentar complejidad adicional en la interfaz.

### D-029 — Configurabilidad comercial con precedencias deterministas

Condor toma como referencia la facilidad de administración de WooCommerce y Magento para reglas comerciales, sin copiar su implementación.

Reglas:

- descuentos por cantidad, producto, categoría u otras dimensiones pueden evolucionar como reglas configurables;
- la flexibilidad no puede producir varios precios efectivos ambiguos;
- toda regla comercial debe tener alcance, prioridad y vigencia explícitos cuando corresponda;
- la UI debe mostrar defaults simples y revelar reglas avanzadas solo bajo demanda;
- no volver a convertir “¿esto debe ser configurable?” en una pregunta recurrente cuando exista variación real entre empresas: la regla general es configurabilidad con defaults e invariantes claros;
- cuando dos reglas puedan competir, el motor de precios debe resolverlas de forma determinista y trazable.


### D-030 — Criterios expertos cerrados para producto, dominio y evolución

Condor adopta los siguientes criterios como defaults de diseño. Son decisiones técnicas y de producto deducibles de la visión existente y no requieren aprobación caso por caso.

#### Propuesta de valor y perfil inicial

- propuesta de valor operativa: **Condor App centraliza y digitaliza la operación comercial de empresas colombianas, empezando por presencia web, e-commerce, catálogo, precios, inventario, clientes y pedidos, con una experiencia simple por defecto y adaptable a cada empresa**;
- el producto no se restringe técnicamente a un sector o tamaño específico;
- la primera validación comercial debe priorizar empresas que hoy operen con procesos manuales, hojas de cálculo, herramientas desconectadas o presencia digital insuficiente;
- especializar marketing o ventas en un nicho futuro no debe introducir acoplamiento del dominio a ese nicho.

#### Mapa de dominios y orden de evolución

El monolito modular se organiza conceptualmente en:

1. Identidad y acceso.
2. Tenant/organización y entidades legales.
3. Sedes y alcance operativo.
4. Catálogo.
5. Precios y reglas comerciales.
6. Inventario.
7. Clientes/CRM comercial básico.
8. Canales, sitio y e-commerce.
9. Pedidos/ventas.
10. Pagos como registro interno y adaptadores futuros.
11. Cumplimiento/entrega.
12. Archivos/media.
13. Workflows/automatización.
14. Auditoría.
15. Configuración/capacidades del tenant.
16. Analítica/reportes.
17. Integraciones.

Dependencias: identidad/tenant preceden a los módulos operativos; catálogo, precios, inventario, clientes y canales alimentan pedidos; workflows e integraciones reaccionan a contratos/eventos estables y no poseen el dominio.

#### Terminología canónica mínima

- **Tenant / cuenta Condor**: espacio aislado contratado por una organización.
- **Entidad legal / razón social**: persona jurídica/fiscal que opera dentro de un tenant.
- **Sede**: ubicación o unidad operativa.
- **Canal**: superficie/origen comercial, por ejemplo e-commerce.
- **Fuente de inventario**: ubicación lógica que posee stock; normalmente una sede, opcionalmente stock propio de canal.
- **Cliente**: contraparte comercial, separada del usuario de acceso.
- **Usuario**: identidad autenticable.
- **Rol**: conjunto configurable de permisos.
- **Producto**: oferta comercial base.
- **Variante**: unidad vendible concreta de un producto cuando aplique.
- **Lista de precios**: conjunto de precios aplicables bajo un contexto comercial.
- **Pedido**: intención/compromiso comercial registrado.
- **Pago**: registro de cobro independiente del estado del pedido.
- **Cumplimiento**: entrega, recogida o resolución de lo vendido.

#### Configurable vs invariante

Configurable por tenant cuando exista variación real: nombres visibles/etapas, roles, permisos CRUD, sedes, categorías comerciales, listas y reglas de precios, canales, fuente de inventario, backorder, compra invitado/cuenta, métodos de pago, workflows y capacidades activas.

Invariantes del núcleo:

- aislamiento entre tenants;
- integridad referencial;
- autorización server-side;
- un precio efectivo determinista por contexto;
- movimientos auditables para mutaciones de inventario;
- trazabilidad de cambios sensibles;
- estados semánticos internos estables;
- idempotencia donde una repetición pueda duplicar efectos;
- transacciones/locking donde la concurrencia pueda romper stock, dinero o unicidad;
- una sola fuente efectiva de inventario por pedido en la etapa inicial;
- una sola categoría comercial principal efectiva por cliente;
- secretos fuera del repositorio.

### D-031 — Modelo de datos y multi-tenancy inicial

- usar **ULID** como identificador de dominio por defecto: opaco, portable, ordenable temporalmente y sin exponer secuencias de negocio;
- MariaDB puede conservar índices internos técnicos cuando Doctrine lo necesite, pero URLs/contratos de dominio no deben depender de IDs secuenciales públicos;
- toda entidad tenant-owned incluye pertenencia inequívoca al tenant o la deriva mediante una relación estructural imposible de cruzar accidentalmente;
- el tenant activo se resuelve una sola vez por request y se propaga mediante un contexto explícito;
- repositorios/servicios tenant-aware deben aplicar aislamiento en backend; nunca confiar en filtros del frontend;
- las relaciones cross-tenant están prohibidas salvo entidades globales explícitas de plataforma;
- Tenant y EntidadLegal son conceptos distintos; un tenant contiene una o varias entidades legales;
- Usuario es identidad; Membresía relaciona usuario con tenant y permite evolución futura a multi-tenant por identidad;
- la autorización efectiva se obtiene de membresía + roles + alcance por sede;
- Sede pertenece al tenant y, cuando corresponda fiscalmente, puede asociarse a una entidad legal;
- Canal pertenece al tenant y referencia su configuración comercial;
- FuenteInventario pertenece al tenant y puede representar sede o stock lógico de canal;
- Producto y Variante pertenecen al tenant; inventario se registra por Variante + FuenteInventario;
- los saldos de stock son proyecciones/estado actual respaldados por movimientos auditables;
- Cliente pertenece al tenant y referencia una categoría comercial principal opcional;
- Pedido captura snapshots necesarios de precio, impuestos/datos comerciales y referencias para que cambios futuros de catálogo no reescriban su historia;
- migraciones mediante Doctrine Migrations; cambios destructivos se separan en pasos expand/contract cuando haya datos reales.

### D-032 — Flujo comercial, stock y consistencia

Flujo de referencia:

1. resolver tenant, canal y contexto comercial;
2. resolver cliente/categoría/lista de precios cuando existan;
3. resolver producto/variante vendible;
4. calcular un único precio efectivo;
5. validar disponibilidad contra la fuente de inventario del canal;
6. crear pedido inicialmente sin exigir integración de pago;
7. al alcanzar el estado semántico **confirmado**, reservar stock por defecto;
8. cancelar o expirar un pedido libera reservas no consumidas;
9. el cumplimiento confirmado convierte la reserva en consumo/salida de inventario;
10. backorder habilitado permite exceder disponibilidad según la regla del producto;
11. toda transición y mutación relevante queda auditada.

Reglas de consistencia:

- crear un borrador no reserva stock por defecto;
- la empresa podrá evolucionar la política de reserva mediante configuración/workflow, pero el default es reserva al confirmar;
- reservar/liberar/consumir debe ser transaccional e idempotente;
- concurrencia de stock usa locking/estrategia atómica en persistencia;
- un pedido no cambia retroactivamente de precio por cambios posteriores de listas/reglas;
- errores de pago externo futuros no deben corromper el pedido ni duplicar cobros; los adaptadores usarán claves idempotentes;
- pedido, pago y cumplimiento conservan estados separados.

### D-033 — Presencia web y CMS configurable

La primera estrategia de presencia web será un **sistema de temas + bloques/secciones configurables**, no un page builder de posicionamiento libre.

- Twig/Symfony renderiza la superficie pública para SEO, rendimiento y simplicidad de hosting;
- cada tenant elige tema, identidad visual y composición de bloques permitidos;
- bloques iniciales pueden incluir hero, texto, imagen, galería, productos/categorías, beneficios, contacto, ubicación, FAQ y llamados a la acción;
- contenido y configuración son datos del tenant, no plantillas PHP duplicadas por cliente;
- React se usa solo en editores/interacciones administrativas donde aporte valor;
- componentes públicos mantienen contratos de contenido estables;
- personalización avanzada futura puede añadir nuevos bloques/temas sin convertir el núcleo en un editor visual arbitrario;
- dominio personalizado y ruta Condor resuelven el mismo tenant y contenido.

### D-034 — Seguridad, tiempo, errores y contratos de aplicación

Defaults técnicos:

- sesiones Symfony server-side con regeneración de ID tras login/cambios sensibles;
- cookies HttpOnly, Secure en producción y SameSite apropiado;
- CSRF obligatorio en mutaciones autenticadas de navegador;
- contraseñas mediante PasswordHasher de Symfony con algoritmo recomendado/auto;
- rate limiting en autenticación, recuperación y endpoints sensibles;
- recuperación de contraseña mediante token de un solo uso, expiración corta y almacenamiento seguro;
- errores públicos no exponen stack traces, SQL, rutas internas ni secretos;
- API REST usa respuestas de error estructuradas con código estable, mensaje legible y detalles de validación por campo cuando aplique;
- validación server-side es autoritativa;
- fechas persistidas en UTC y convertidas a la zona horaria del tenant/usuario para presentación;
- zona horaria inicial por defecto: **America/Bogota**;
- locale inicial: **es-CO**;
- moneda inicial por defecto: **COP**, pero importes se almacenan como enteros en unidad mínima o decimal exacto, nunca float binario;
- logs técnicos y auditoría de negocio son separados;
- auditoría registra actor, tenant, acción, entidad, identificador, timestamp y contexto/cambios relevantes sin almacenar secretos.

### D-035 — Workflows, integraciones y portabilidad

- motor de workflows interno y transversal basado en eventos semánticos estables;
- cada workflow define trigger, condiciones y acciones;
- ejecuciones tienen estado, timestamps, resultado y trazabilidad;
- las acciones internas llaman servicios de aplicación, no escriben tablas de otros módulos directamente;
- eventos se publican después de confirmar la transacción que originó el cambio o mediante patrón equivalente que evite efectos fantasma;
- no se requieren colas externas inicialmente; el contrato debe permitir introducirlas cuando escala/latencia lo justifique;
- proveedores externos se conectan mediante puertos/adaptadores;
- pagos, email, archivos/object storage, facturación, envíos, caché y colas no deben acoplar el dominio a Hostinger;
- archivos comienzan en almacenamiento local abstraído y pueden migrar a S3;
- MariaDB se mantiene compatible con migración futura a servicio administrado;
- configuración por entorno y secretos permiten mover compute sin cambiar reglas de negocio;
- no se adoptan microservicios hasta que métricas reales justifiquen separar un módulo.

### D-036 — Operación, calidad y evolución

- observabilidad mínima: logs estructurados, correlation/request ID, métricas de errores/latencia y health checks sin secretos;
- errores inesperados deben ser agregables y alertables; no depender únicamente de revisar logs manualmente;
- backups de base de datos y archivos son programados, cifrados cuando corresponda y su restauración se prueba periódicamente;
- performance se optimiza por medición; evitar N+1, paginar colecciones y cachear solo datos con política clara de invalidación;
- SEO de superficies públicas: SSR, metadata, canonical, sitemap, robots, datos estructurados cuando apliquen y Core Web Vitals razonables;
- accesibilidad: HTML semántico, teclado, labels, contraste y estados de foco como baseline;
- CI crece de forma path-sensitive con unit/contract/integration/E2E según riesgo;
- dependencias y vulnerabilidades se revisan automáticamente;
- deuda técnica se registra como trabajo explícito cuando afecte seguridad, velocidad o mantenibilidad;
- analítica de producto se instrumenta cuando existan flujos reales; inicialmente medir activación y uso de capacidades sin recolectar datos innecesarios.



### D-037 — API-first y preparación para aplicaciones móviles

Condor debe poder incorporar clientes nativos iOS y Android sin reescribir la lógica de negocio ni crear un backend paralelo.

Reglas:

- la lógica de negocio vive en dominio/servicios de aplicación del backend y no en componentes Twig o React;
- las capacidades que necesiten UI interactiva se exponen mediante contratos de aplicación/API reutilizables por web y, posteriormente, móvil;
- el backoffice React consume los mismos casos de uso y reglas server-side que consumirían clientes móviles;
- Twig puede renderizar superficies públicas directamente por rendimiento/SEO, pero no se convierte en la única puerta de entrada a reglas de negocio;
- autenticación/autorización se diseñan para permitir evolución desde sesión web hacia credenciales/tokens apropiados para clientes móviles, sin debilitar el modelo de permisos;
- endpoints y DTOs no deben depender de detalles visuales de una pantalla concreta;
- versionar contratos externos cuando cambios incompatibles lo requieran; no versionar prematuramente cada endpoint;
- archivos, media, paginación, errores e idempotencia deben usar contratos consumibles por clientes móviles;
- añadir una app móvil en el futuro debe ser principalmente un nuevo cliente de Condor, no un segundo producto con lógica duplicada.

### D-038 — Roadmap como libro ejecutivo vivo

El Issue #1 conserva su función canónica de progreso, pero además debe poder ser leído de forma autónoma por socios y stakeholders no técnicos.

Reglas:

- mantener al inicio un **Resumen ejecutivo vivo** con propósito, estado actual, versión, producción, trabajo actual, siguiente hito y decisiones humanas pendientes;
- explicar estados con una leyenda corta;
- el detalle histórico permanece debajo y no se borra para maquillar avance;
- distinguir claramente entre **definido**, **implementado**, **validado en código/CI**, **desplegado** y **validado en producción**;
- cada trabajo significativo debe actualizar el resumen ejecutivo cuando cambie alguno de esos datos;
- el roadmap resume decisiones, pero las reglas durables completas permanecen en ESPECIFICACIONES.md;
- evitar jerga innecesaria en el resumen ejecutivo; el detalle técnico puede permanecer en fases/Issues enlazados.

### D-039 — Identidad visible de versión

Cuando exista la aplicación Condor:

- la versión de producto debe mostrarse discretamente en el footer del sitio público;
- la pantalla de login del administrador debe mostrar la misma versión;
- ambas superficies leen la fuente canónica de versión; no duplican un literal independiente;
- la primera versión visible será **V 0.1.0**;
- mostrar versión no equivale a declarar producción validada: el roadmap mantiene separada la evidencia de deploy/producción.



### D-040 — Baseline integral de seguridad y confiabilidad (Fase 4 cerrada)

La seguridad de Condor es una propiedad transversal del producto y una condición de entrega. Esta decisión **cierra la definición de toda la Fase 4**. Lo que permanezca pendiente después de esta decisión es implementación, prueba o evidencia operativa; no una decisión de arquitectura abierta.

#### 1. Autenticación segura

- usar Symfony Security como mecanismo central de autenticación;
- las contraseñas se procesan exclusivamente con Symfony PasswordHasher usando el algoritmo recomendado/auto y rehash transparente cuando cambie el coste o algoritmo;
- nunca almacenar, registrar, enviar por correo ni recuperar una contraseña en texto plano;
- respuestas de login y recuperación no revelan si una cuenta existe;
- normalizar el identificador de acceso de forma consistente y aplicar unicidad a nivel de persistencia;
- regenerar el identificador de sesión después de autenticación, elevación de privilegios y cambios de credenciales;
- al cambiar contraseña, cerrar o invalidar las demás sesiones/credenciales persistentes cuando sea técnicamente viable;
- MFA queda preparado como extensión posterior y no es requisito obligatorio de la primera versión.

#### 2. Política de sesión

- sesiones web administradas server-side;
- cookie de sesión con `HttpOnly`, `Secure` en HTTPS y `SameSite=Lax` por defecto; usar una política más estricta cuando el flujo lo permita;
- no almacenar tokens de autenticación sensibles en `localStorage`;
- timeout de inactividad administrativo inicial: **2 horas**;
- duración absoluta inicial de una sesión administrativa: **12 horas**;
- “recordarme” no se habilita hasta implementar tokens persistentes rotables, revocables y separados de la cookie de sesión;
- logout invalida la sesión server-side;
- cambios críticos de seguridad pueden exigir reautenticación.

#### 3. Protección CSRF

- toda mutación autenticada iniciada desde navegador requiere protección CSRF;
- formularios Symfony usan tokens CSRF;
- llamadas AJAX/React autenticadas por cookie incluyen un token CSRF explícito;
- endpoints diseñados para futuros clientes móviles con credenciales bearer no dependen de CSRF, pero sí de autenticación/autorización fuerte;
- nunca desactivar CSRF globalmente para “hacer funcionar” una pantalla.

#### 4. Consultas parametrizadas e inyección

- Doctrine ORM/DBAL y parámetros enlazados son el camino por defecto para persistencia/consulta;
- queda prohibida la concatenación de input no confiable dentro de SQL, DQL, expresiones de filtro o nombres dinámicos no validados;
- ordenamiento, campos seleccionables y operadores dinámicos se resuelven mediante allowlists;
- comandos del sistema, rutas de archivo y plantillas siguen el mismo principio: datos no confiables nunca se convierten directamente en instrucciones.

#### 5. Validación server-side

- el servidor es la autoridad final de validación aunque la UI ya haya validado;
- DTOs/commands de entrada declaran tipos y constraints;
- validar formato, longitud, rango, relaciones, pertenencia al tenant, estado de dominio y permisos;
- rechazar propiedades inesperadas en contratos sensibles cuando puedan introducir mass assignment;
- normalizar antes de persistir solo cuando exista una regla explícita y predecible;
- mensajes públicos son útiles sin revelar internals.

#### 6. Rate limiting y defensa contra abuso

Baseline inicial, ajustable por medición:

- login: máximo **10 intentos por 15 minutos** por combinación de identidad + IP y un límite adicional por IP;
- recuperación de contraseña: máximo **5 solicitudes por hora** por identidad/IP, siempre con respuesta genérica;
- endpoints administrativos sensibles y acciones costosas incorporan límites específicos cuando aparezcan;
- rate limiting no sustituye autorización ni bloqueos de dominio;
- registrar señales de abuso sin almacenar secretos;
- incrementos de límites se justifican por telemetría, no eliminando el control.

#### 7. Secretos y variables de entorno

- secretos nunca se versionan en Git;
- producción, pruebas y desarrollo usan credenciales separadas;
- `.env` puede contener valores no sensibles/defaults; secretos locales permanecen en archivos ignorados y secretos de producción en el mecanismo seguro disponible del entorno;
- claves, tokens y passwords se rotan cuando exista exposición, cambio de personal/proveedor o política de expiración;
- logs, dumps, excepciones, artifacts CI y screenshots no deben contener secretos;
- CI usa secretos con mínimo alcance y permisos mínimos.

#### 8. Headers de seguridad

Baseline de respuestas web:

- `Content-Security-Policy` explícita y progresivamente estricta; evitar `unsafe-inline`/`unsafe-eval` salvo excepción temporal documentada;
- `frame-ancestors 'none'` por defecto para superficies administrativas y páginas que no deban embeberse;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `Permissions-Policy` restrictiva para capacidades no usadas;
- HSTS se activa únicamente después de confirmar HTTPS correcto y alcance de subdominios, evitando bloquear dominios personalizados por una configuración prematura;
- no depender de headers heredados cuando CSP ofrece un control más fuerte;
- CORS no se abre globalmente: orígenes explícitos según cliente/canal real.

#### 9. Logging seguro y manejo de errores

- logging técnico separado de auditoría de negocio;
- logs estructurados con timestamp, nivel, entorno, correlation/request ID y contexto técnico mínimo;
- nunca registrar passwords, tokens, cookies completas, secretos, números completos de medios de pago ni payloads sensibles innecesarios;
- PII se minimiza/redacta cuando no sea necesaria para diagnóstico;
- errores públicos usan un ID/correlation ID y no muestran stack traces, SQL, filesystem paths ni secretos;
- producción desactiva debug público;
- retención de logs es limitada y revisable según necesidad operativa/legal.

#### 10. Auditoría administrativa

Registrar como eventos de auditoría, cuando apliquen:

- login/logout y fallos relevantes de autenticación sin almacenar credenciales;
- cambios de roles/permisos y membresías;
- cambios de configuración del tenant;
- altas/bajas/archivados sensibles;
- cambios manuales de precio;
- movimientos/ajustes/transferencias de inventario;
- cambios de estado de pedidos/pagos/cumplimientos;
- cambios de dominios, entidades legales y sedes;
- operaciones destructivas o de seguridad.

Cada registro incluye actor, tenant, sede si aplica, entidad/ID, acción, timestamp UTC, correlation ID, origen/contexto y un resumen seguro de cambios. La auditoría es lógicamente append-only: no se edita para ocultar historia operativa.

#### 11. Backups y restauración verificable

Baseline inicial:

- backup de MariaDB **diario**;
- archivos/media incluidos en una política de backup o replicación equivalente;
- conservar al menos **30 días** de puntos de recuperación mientras la capacidad del hosting lo permita;
- mantener al menos una copia fuera del mismo fallo lógico/físico del hosting principal cuando el producto tenga datos reales de clientes;
- cifrar backups en tránsito y en reposo cuando el medio lo soporte;
- acceso a backups limitado por mínimo privilegio;
- un backup no se considera válido hasta que pueda restaurarse;
- objetivo inicial: **RPO ≤ 24 h** y **RTO ≤ 8 h**; endurecer según necesidades reales/contratos.

#### 12. Rehearsal de recuperación

- realizar restauraciones ensayadas en un entorno seguro y aislado;
- frecuencia inicial: **trimestral** una vez existan datos reales de producción, y adicionalmente después de cambios relevantes en backup/migraciones;
- el rehearsal debe verificar base de datos, archivos, configuración necesaria y pasos documentados;
- nunca probar restauración destruyendo la producción;
- registrar fecha, duración, RPO/RTO observados y hallazgos;
- cualquier fallo del rehearsal genera trabajo correctivo antes de considerar confiable la estrategia.

#### 13. Dependencias y vulnerabilidades

- Composer y npm usan lockfiles reproducibles;
- ejecutar revisión automática de dependencias en CI cuando existan dependencias de aplicación;
- `composer audit` y auditoría equivalente de npm forman parte del baseline;
- habilitar alertas/actualizaciones automáticas de dependencias cuando sean compatibles con el repositorio;
- findings críticos/altos explotables en el contexto real bloquean entrega hasta corregir, mitigar o documentar explícitamente una aceptación excepcional;
- SonarQube Cloud, CodeRabbit y análisis de dependencias se complementan; ninguno sustituye pruebas ni revisión humana/técnica;
- actualizar dependencias de forma controlada, con pruebas de regresión.

#### 14. Operaciones destructivas y producción

- producción no ejecuta migraciones destructivas automáticamente;
- schema changes con datos reales usan estrategia **expand → migrate/backfill → contract** cuando corresponda;
- borrar/archivar datos operativos requiere autorización server-side y confirmación proporcional al impacto;
- priorizar soft-delete/archivo donde conservar historia sea importante;
- acciones masivas o irreversibles muestran alcance antes de ejecutar y generan auditoría;
- automatizaciones/IA nunca ejecutan operaciones destructivas de producción sin autorización explícita cuando exista riesgo irreversible;
- cambios sensibles de configuración requieren permisos específicos y, cuando el riesgo lo amerite, reautenticación.

#### 15. Seguridad multi-tenant e IDOR

- toda lectura/mutación tenant-owned verifica pertenencia del recurso al tenant activo en backend;
- conocer un ULID/URL de otro tenant nunca concede acceso;
- la autorización se valida tanto a nivel de capacidad como de objeto/recurso;
- repositorios y casos de uso evitan consultas globales accidentales;
- CI debe incluir pruebas negativas que intenten leer, modificar, exportar o asociar recursos de otro tenant;
- las pruebas cross-tenant son requisito de aceptación de módulos que manejen datos tenant-owned.

#### 16. XSS y contenido enriquecido

- Twig mantiene autoescape; React no renderiza HTML arbitrario sin sanitización explícita;
- cualquier HTML enriquecido editable se sanitiza con allowlist server-side;
- CSP complementa, pero no sustituye, escaping/sanitización;
- URLs, atributos y contenido generado por usuario se validan según su contexto;
- no usar `dangerouslySetInnerHTML` salvo componente aislado con contenido ya sanitizado y prueba específica.

#### 17. Uploads y archivos

- validar tamaño, tipo permitido y contenido/MIME real; no confiar únicamente en extensión;
- generar nombres internos opacos y evitar rutas controladas por el usuario;
- archivos subidos no se ejecutan como código;
- preferir almacenamiento fuera del webroot o servirlos mediante una capa que fuerce headers seguros;
- imágenes/documentos se procesan con límites de memoria/tamaño;
- añadir análisis antimalware cuando el tipo de archivo, exposición o clientes reales lo justifiquen.

#### 18. SSRF y consumo de URLs externas

Cuando Condor permita importar/fetch de URLs externas:

- aceptar solo esquemas/protocolos explícitos;
- bloquear localhost, loopback, redes privadas, link-local y metadata endpoints;
- resolver/revalidar destino ante redirects;
- usar timeouts, límites de tamaño y número de redirects;
- no enviar secretos/cookies internas a destinos arbitrarios;
- preferir integraciones por adapters con endpoints conocidos sobre fetch genérico.

#### 19. Concurrencia, idempotencia y operaciones sensibles

- stock, pagos futuros, transferencias y operaciones susceptibles de doble ejecución usan transacciones y locking/operaciones atómicas;
- endpoints/comandos que puedan reintentarse con efectos externos usan idempotency keys cuando corresponda;
- un retry no debe duplicar cobros, movimientos de inventario, pedidos ni efectos externos;
- eventos externos/webhooks futuros se deduplican y verifican antes de ejecutar cambios de dominio.

#### 20. Pruebas y gate de seguridad

Cada vertical slice debe incorporar las pruebas de seguridad pertinentes a su superficie:

- autenticación/autorización;
- aislamiento cross-tenant;
- CSRF;
- validación e input malicioso;
- IDOR;
- permisos por sede;
- regresiones de findings relevantes.

La Fase 4 queda **definida y cerrada** con este baseline. La implementación se verifica progresivamente en cada vertical slice y en gates dedicados; no se volverán a abrir estas decisiones salvo evidencia técnica o regulatoria nueva.



### D-041 — Baseline de sistema de diseño y experiencia (Fase 5)

Condor adopta un sistema de diseño **token-first, accesible, sobrio y modular**. La identidad visual final de marca —logo, símbolo y paleta de marca definitiva— requiere aprobación subjetiva del propietario, pero **no bloquea** la implementación del sistema de diseño ni del producto.

#### Principios visuales

- priorizar claridad, densidad controlada y jerarquía de información sobre ornamentación;
- apariencia profesional, contemporánea y confiable para software empresarial colombiano;
- evitar el aspecto genérico de “dashboard plantilla” y también evitar una identidad excesivamente decorativa que reduzca legibilidad;
- usar progresive disclosure: lo frecuente visible, lo avanzado bajo demanda;
- estados, acciones y jerarquías deben ser comprensibles también sin depender únicamente del color;
- la personalización por tenant no puede romper accesibilidad ni consistencia del shell administrativo.

#### Tokens y escalas

El design system usa **CSS custom properties / tokens semánticos** como fuente de verdad visual.

- spacing base: múltiplos de **4 px**;
- escala recomendada: 4, 8, 12, 16, 24, 32, 48 y 64 px;
- radio base: **8 px**; controles compactos pueden usar 6 px y superficies principales 12 px;
- tipografía inicial: stack de sistema de alta legibilidad, sin dependencia de una fuente remota para funcionar;
- tamaño base de texto: **16 px**; información secundaria no debe bajar de 12 px;
- line-height de cuerpo aproximado 1.5;
- pesos tipográficos limitados y consistentes: regular, medium/semibold y bold solo cuando la jerarquía lo requiera;
- colores se definen semánticamente: background, surface, text-primary, text-secondary, border, accent, success, warning, danger, info, focus;
- la futura paleta de marca modifica tokens de marca/acento, no los contratos de componentes.

#### Densidad y superficies

- densidad por defecto: cómoda para administración diaria, sin desperdiciar espacio;
- tablas/listados pueden ofrecer modo compacto cuando exista necesidad real;
- cards solo cuando representen agrupaciones reales; no convertir toda la interfaz en mosaicos;
- formularios priorizan una columna legible y usan múltiples columnas solo cuando reduzca scroll sin perder comprensión;
- acciones primarias deben ser inequívocas; evitar múltiples botones primarios competidores en la misma región.

#### Componentes reutilizables mínimos

Antes de multiplicar variaciones por módulo, el sistema debe disponer de primitives/components reutilizables:

- Button, IconButton y ButtonGroup;
- Input, Textarea, Select, Combobox y Checkbox/Switch;
- Field/Label/Help/Error;
- FormSection;
- Modal/Dialog y ConfirmationDialog;
- Drawer/Sheet;
- Toast/InlineAlert;
- Badge/Status;
- Tabs;
- Table/DataGrid baseline;
- Pagination;
- EmptyState;
- Skeleton/Spinner;
- Breadcrumbs;
- Search/FilterBar;
- Dropdown/Menu;
- Card/Panel solo donde aporte agrupación;
- Date/Time y Money display/inputs con locale correcto;
- Avatar/UserMenu;
- navegación/sidebar.

No crear componentes duplicados por módulo si el comportamiento es equivalente.

#### Navegación administrativa

- desktop: **sidebar persistente/colapsable + top bar de contexto/utilidades**;
- tablet: sidebar colapsable;
- mobile: top bar + drawer de navegación; no forzar una bottom-nav fija porque los módulos son configurables y pueden crecer;
- navegación se genera a partir de capacidades/módulos habilitados y permisos efectivos;
- módulos no autorizados no se muestran, pero la seguridad real sigue en backend;
- ubicación actual siempre visible mediante título/contexto y breadcrumbs cuando exista profundidad;
- acciones globales y acciones de página se mantienen separadas;
- búsqueda global se incorpora cuando existan suficientes entidades para justificarla, no como requisito del primer slice.

#### Responsive

- diseñar desde **360 px** de ancho útil como baseline mínimo;
- ningún flujo crítico requiere hover;
- tablas densas deben degradar a scroll horizontal controlado, columnas priorizadas o vistas resumidas, no a contenido ilegible;
- formularios y acciones conservan orden lógico en móvil;
- targets táctiles: mínimo aproximado **44×44 px** para acciones principales/interactivas;
- componentes se prueban en viewport móvil y desktop desde el slice que los introduce.

#### Accesibilidad

Objetivo baseline: **WCAG 2.2 AA** en las superficies propias de Condor.

- navegación completa por teclado;
- foco visible y consistente;
- contraste suficiente;
- labels programáticos;
- mensajes de error asociados al campo y resumen cuando el formulario sea largo;
- estados no comunicados solo mediante color;
- orden DOM y foco coherentes en modals/drawers;
- soporte de `prefers-reduced-motion`;
- animaciones funcionales, breves y nunca necesarias para entender el estado.

#### Estados de interfaz

Toda pantalla/consulta relevante debe contemplar explícitamente:

- loading;
- empty;
- success;
- validation error;
- permission denied;
- not found;
- recoverable technical error;
- destructive confirmation;
- offline/network error solo cuando el cliente lo pueda detectar de forma útil.

Un error recuperable debe ofrecer la acción siguiente: reintentar, corregir, volver o contactar soporte con correlation ID cuando aplique.

#### Lenguaje y microcopy

- español de Colombia natural, profesional y directo;
- verbos de acción concretos: “Guardar”, “Crear producto”, “Transferir stock”;
- evitar jerga técnica cuando exista equivalente de negocio;
- confirmaciones destructivas explican **qué se afectará**;
- mensajes de error explican qué ocurrió y qué puede hacer la persona, sin filtrar internals;
- usar terminología canónica de D-030 de forma consistente.

#### Identidad visual pendiente

Queda deliberadamente abierta únicamente la **aprobación final de identidad de marca**: logo/símbolo, paleta principal de marca y estilo gráfico definitivo. Esa decisión puede aplicarse posteriormente sobre los tokens sin reescribir componentes ni flujos.

### D-042 — Estrategia de vertical slices y Definition of Done (Fase 6)

Condor se construirá mediante vertical slices pequeños pero completos. Cada slice debe atravesar las capas que realmente necesite —UI, contratos, dominio, persistencia, permisos, pruebas y observabilidad— y dejar una capacidad coherente, evitando construir “capas vacías” sin uso.

#### Primer vertical slice seleccionado

**Slice 1 — Fundación + onboarding del tenant**

Objetivo: demostrar el recorrido técnico completo y dejar la base real sobre la que se construyen los módulos comerciales.

Alcance:

1. bootstrap real de Symfony 7.4 + Doctrine + MariaDB;
2. React + TypeScript + Vite integrado al proyecto para administración;
3. fuente canónica de versión **V 0.1.0**;
4. versión visible en footer público y login administrativo;
5. login/logout seguro;
6. modelo Tenant;
7. modelo EntidadLegal;
8. modelo Sede;
9. Usuario + Membresía;
10. creación inicial de tenant + primera entidad legal + primera sede predeterminada + propietario administrador;
11. `TenantResolver` + `TenantContext`;
12. shell administrativo responsive mínimo;
13. migraciones Doctrine;
14. auditoría mínima de creación/configuración inicial;
15. pruebas de aislamiento cross-tenant;
16. smoke público y autenticado aislado cuando el entorno lo permita.

El slice no necesita todavía catálogo, inventario ni pedidos; su valor es validar el núcleo de identidad, tenancy, persistencia, permisos, seguridad y entrega real.

#### Secuencia inicial de slices

Después del Slice 1, el orden recomendado es:

- **Slice 2 — Roles y permisos por sede**;
- **Slice 3 — Catálogo: producto + variante**;
- **Slice 4 — Inventario: fuente + stock + movimientos + transferencias**;
- **Slice 5 — Clientes + categoría comercial + listas/reglas de precio**;
- **Slice 6 — Canal e-commerce + catálogo público + disponibilidad/precio efectivo**;
- **Slice 7 — Pedido manual/e-commerce + reserva/liberación/consumo de stock**;
- **Slice 8 — CMS público por temas + bloques configurables**;
- **Slice 9 — Workflows internos sobre eventos ya existentes**.

El orden puede cambiar por evidencia de uso o necesidad comercial, pero no por comodidad técnica aislada.

#### Definition of Done obligatoria por slice

Un slice está terminado únicamente cuando, según aplique:

- el caso de uso está implementado end-to-end;
- backend es autoridad de reglas y validación;
- persistencia/migraciones están versionadas;
- permisos y aislamiento tenant están aplicados;
- API/contratos son reutilizables por web y futuros clientes móviles;
- UI contempla loading/empty/error/success;
- responsive validado en móvil y desktop;
- accesibilidad relevante validada;
- controles de Fase 4 aplicables implementados;
- unit/contract/integration tests cubren invariantes estables;
- Playwright cubre el flujo de usuario cuando la superficie existe;
- existen pruebas negativas de seguridad/tenant cuando maneja datos tenant-owned;
- errores tienen correlation/request ID y logging suficiente;
- no se introducen secretos ni dependencias de producción innecesarias;
- CI, SonarQube y CodeRabbit aplicables están verdes o sus findings válidos corregidos;
- PR se hace squash merge;
- se verifica el SHA exacto de `main`;
- deploy y validación de producción se registran por separado;
- roadmap ejecutivo y detalle de fase se actualizan con Issue/PR/SHA/evidencia.

#### Política de alcance

- evitar slices gigantes que mezclen dominios no necesarios;
- evitar “backend primero por meses” o “UI mock sin dominio real” como estrategia normal;
- una abstracción nueva debe justificarla al menos un caso real del slice;
- si un requisito menor no bloquea coherencia, se registra y se difiere en vez de expandir indefinidamente el slice;
- decisiones nuevas realmente durables se actualizan en ESPECIFICACIONES.md en el mismo trabajo.

#### Métrica de avance

La Fase 6 se mide por **slices integrados y validados**, no por porcentaje subjetivo de archivos creados. Cada slice completado debe tener evidencia trazable en el roadmap.


### D-043 — Entrega, release identity y validación de producción (Fase 7)

Condor separa de forma estricta **código validado**, **despliegue observado** y **producción validada**. Ningún merge o CI verde debe presentarse como validación de producción.

#### Estados canónicos de una entrega

Una entrega puede avanzar por estos estados:

1. **VALIDATED_IN_CODE** — PR/gates verdes y SHA exacto de `main` validado.
2. **DEPLOY_OBSERVED** — se comprobó que Hostinger está sirviendo la release esperada.
3. **VALIDATED_IN_PRODUCTION** — los smoke checks aplicables pasan contra la release efectivamente desplegada.

El roadmap y README no pueden saltar directamente del primer estado al tercero.

#### Release identity

- la versión humana permanece en la fuente canónica `config/version.php`;
- cada build/deploy debe asociar **versión + SHA exacto + timestamp de build/release**;
- el runtime dispone de metadata de release generada a partir del build, no escrita manualmente en múltiples lugares;
- sitio público y login administrativo muestran la versión humana acordada;
- un endpoint/marker público seguro de release puede exponer únicamente datos no sensibles como `status`, `version` y `release_sha`; nunca rutas, secretos, variables de entorno ni detalles internos;
- logs y errores incluyen `release_sha` para correlacionar incidentes con el código desplegado.

#### Compatibilidad con Hostinger shared hosting

Mientras Hostinger shared hosting sea el runtime:

- PHP 8.5 + Symfony 7.4 + MariaDB son las dependencias de runtime;
- Node.js se usa solo en desarrollo/CI/build; producción no necesita un proceso Node persistente;
- no se requiere Docker en producción;
- no se requieren workers residentes, WebSockets persistentes ni daemons para que el flujo principal funcione;
- tareas asíncronas iniciales deben poder resolverse síncronamente, por cron o mediante jobs acotados compatibles con shared hosting;
- el document root público debe apuntar únicamente a la superficie pública de Symfony/`public/` cuando el hosting lo permita;
- secretos y configuración de producción permanecen fuera del repositorio;
- assets Vite llegan ya compilados a la release desplegable;
- la aplicación debe arrancar sin ejecutar migraciones destructivas automáticamente;
- **caché de contenedor tras deploy (Issue #144):** el despliegue por Git de Hostinger preserva `var/cache/prod` entre builds (coincide con `.gitignore`) en vez de regenerarlo. Un deploy que cambie servicios/dependencias del contenedor de Symfony deja el contenedor compilado desincronizado con el código y produce un `Error` fatal (`TypeError`/`ArgumentCountError`/clase inexistente) originado dentro de los archivos compilados, tan temprano que ni el diagnóstico sanitizado de #93/#133 alcanza a interceptarlo. El hPanel de este plan **no expone un comando de post-deploy nativo** (verificado en vivo), así que la mitigación tiene dos capas y ninguna depende de un paso manual por SSH tras el deploy:
  1. `public/index.php` detecta ese patrón exacto (`App\Infrastructure\Runtime\ContainerRecovery::looksLikeStaleContainer()`: un `Error`, no una `Exception`, originado dentro de `var/cache/prod`), limpia la caché de forma exclusiva (`flock` no bloqueante) y reintenta la misma solicitud una sola vez — autorreparación transparente en la primera solicitud afectada, sin ventana de error visible para un humano;
  2. un cron job en hPanel (`*/5 * * * *`) ejecuta `scripts/post-deploy.sh` (`cache:clear` + `cache:warmup` en `prod`) como respaldo, cubriendo el caso en que la primera solicitud tras el deploy no la sirve un humano (webhook, bot, smoke automático);
  - el hosting compartido puede tener el `php` del `PATH` apuntando a una versión distinta de la que sirve el sitio (visto en producción: CLI por defecto en 8.2 mientras el sitio corre en 8.5 vía `/opt/alt/php85/usr/bin/php`); scripts operativos deben probar binarios conocidos de la versión objetivo antes de caer al `php` del `PATH`.

#### Build reproducible

La release debe poder reconstruirse desde Git + lockfiles + configuración externa:

- Composer usa lockfile y build de producción sin dependencias de desarrollo;
- npm/Vite usa lockfile para generar assets estáticos;
- CI es el lugar preferido para producir/validar artefactos;
- el mecanismo concreto de transporte a Hostinger puede evolucionar, pero el contenido desplegado debe corresponder a un SHA verificable;
- no introducir pasos manuales irrepetibles como requisito normal de release.

#### Migraciones de producción

- despliegue de código y migración de base de datos son etapas separables;
- migraciones aditivas/compatibles pueden automatizarse en el futuro solo cuando exista rollback/observabilidad suficiente;
- migraciones destructivas nunca se ejecutan automáticamente;
- cambios con datos reales usan expand → migrate/backfill → contract;
- el roadmap registra por separado “código desplegado” y “migración aplicada” cuando el cambio lo requiera.

#### Observación del despliegue

Tras merge a `main`:

- registrar SHA y versión esperados;
- esperar/observar el despliegue automático;
- verificar el marker de release o evidencia equivalente;
- si el SHA/version no coincide, el despliegue no se considera observado;
- fallos de deploy no modifican el estado del código en GitHub: se registran como incidente de entrega.

#### Smoke público

Smoke público obligatorio cuando exista la aplicación:

- `GET /` o una ruta pública estable responde sin error fatal;
- `GET /health` o equivalente responde en tiempo acotado;
- marker de release coincide con versión/SHA esperados;
- login administrativo carga;
- ninguna de estas comprobaciones muta datos de negocio;
- respuestas no exponen secretos, stack traces ni información de infraestructura innecesaria.

#### Smoke autenticado

Se habilita únicamente cuando exista un usuario y tenant de prueba aislados:

- credenciales exclusivas de smoke/E2E, nunca cuentas personales ni clientes reales;
- mínimo privilegio;
- tenant sin datos reales;
- primera etapa preferentemente read-only: login → shell → endpoint protegido → logout;
- cualquier mutación futura de smoke usa datos desechables identificables y limpieza segura dentro del tenant de prueba;
- una falla de cleanup nunca autoriza borrados globales ni acceso a otros tenants.

#### Medición instruction-to-production

Condor medirá throughput con timestamps observables del flujo:

- reserva/inicio del Issue;
- primer commit;
- apertura del PR;
- inicio/fin de CI;
- merge;
- deploy observado;
- smoke/validación de producción.

Métricas iniciales:

- lead time Issue → merge;
- PR cycle time;
- duración de CI;
- merge → deploy observado;
- deploy observado → producción validada;
- lead time total Issue → producción validada.

Primero se crea baseline real; luego se optimiza el cuello de botella dominante. No se sacrifican gates críticos para mejorar una métrica.

#### Primer deploy funcional V 0.1.0

El primer deploy funcional **V 0.1.0** permanece pendiente hasta que:

- el Slice 1 esté implementado;
- la release V 0.1.0 esté realmente servida por Hostinger;
- release marker/version correspondan al SHA esperado;
- smoke público pase;
- smoke autenticado pase cuando ya exista la superficie autenticada aislada;
- el README y roadmap registren evidencia real.

### D-044 — Operación, observabilidad y evolución continua (Fase 8)

La operación de Condor debe ser observable, recuperable y mantenible desde shared hosting, sin acoplarse a herramientas propietarias que impidan migrar posteriormente a infraestructura administrada.

#### Observabilidad

Baseline inicial:

- Monolog/logging estructurado;
- `request_id`/correlation ID por request;
- contexto de release: versión + SHA;
- tenant ID interno cuando sea seguro y útil para diagnóstico, evitando nombres/PII innecesarios;
- niveles coherentes: debug solo fuera de producción, info, warning, error y critical;
- endpoint de health seguro;
- medición de latencia, errores y disponibilidad cuando exista tráfico real;
- adaptador de observabilidad para poder migrar luego a un servicio externo sin reescribir dominio.

El health endpoint:

- verifica que el proceso de aplicación responde;
- puede comprobar DB con timeout estricto cuando sea útil;
- no expone credenciales, hostname interno, SQL, stack ni configuración;
- diferencia degradación de fallo total cuando la arquitectura lo permita.

#### Gestión de errores y alertas

- errores inesperados obtienen correlation ID;
- excepciones se clasifican entre error de usuario/validación, negocio esperado, dependencia externa y fallo interno;
- fallos internos no se muestran crudos a usuarios;
- eventos `error` y `critical` deben poder alimentar un canal de alertas;
- proveedor/canal concreto de alertas permanece intercambiable;
- alertar prioritariamente por: 5xx sostenidos, smoke de producción fallido, deploy inconsistente, backup/recovery fallido y fallos repetidos de autenticación/seguridad;
- evitar alertas por cada evento individual cuando generen ruido; agrupar/deduplicar.

#### Backups y recovery

Se reutiliza el baseline de D-040:

- DB diaria;
- media/archivos cubiertos;
- retención inicial de 30 días cuando el hosting lo permita;
- copia fuera del mismo punto de fallo cuando existan datos reales;
- RPO inicial ≤ 24 h;
- RTO inicial ≤ 8 h;
- rehearsal trimestral en entorno aislado.

Fase 8 no reabre estas decisiones: implementa, mide y demuestra restauración.

#### Performance y capacidad

Objetivos iniciales, medidos antes de convertirlos en gates rígidos:

**Web pública / Core Web Vitals**
- LCP p75 ≤ 2.5 s;
- INP p75 ≤ 200 ms;
- CLS p75 ≤ 0.1.

**Backend**
- endpoints internos comunes: objetivo p95 ≤ 500 ms de tiempo server-side cuando no dependan de terceros;
- operaciones pesadas deben paginarse, acotarse o pasar a procesamiento diferido compatible con la infraestructura;
- evitar N+1 y consultas sin índice en rutas críticas;
- toda lista potencialmente grande usa paginación;
- cache solo donde exista política explícita de invalidación;
- uploads y exports tienen límites de tamaño/tiempo.

Capacidad:

- medir antes de escalar;
- vigilar DB, disco, memoria/CPU disponibles y tiempos de respuesta cuando Hostinger exponga la señal;
- una limitación recurrente del shared hosting es evidencia para migrar capacidad/servicio, no razón para introducir microservicios prematuramente.

#### SEO multi-tenant

Para superficies públicas indexables:

- SSR Twig como base;
- title/description configurables;
- canonical correcto;
- sitemap por tenant/canal;
- robots controlado por entorno y publicación;
- admin, login y superficies privadas usan `noindex`;
- datos estructurados cuando la entidad sea completa: Organization/LocalBusiness/Product/BreadcrumbList según aplique;
- páginas archivadas o eliminadas usan estado HTTP/redirección coherente;
- URLs estables, legibles y derivadas de slugs con estrategia de colisión.

Regla de dominio duplicado:

- si un tenant tiene dominio personalizado verificado y marcado como primario, ese dominio es el canonical público;
- la ruta equivalente bajo `condorapp.com.co/<tenant>` debe canonicalizar hacia el dominio primario o quedar noindex según la estrategia del canal;
- si no existe dominio personalizado primario, la URL Condor es canonical.

#### Analítica de producto

- instrumentación provider-agnostic mediante una interfaz interna de eventos;
- no bloquear el primer slice esperando un proveedor;
- eventos iniciales útiles: onboarding completado, primer usuario invitado, producto creado, primer movimiento de inventario, primer cliente creado, primer pedido creado y módulo activado;
- eventos usan IDs opacos y propiedades operativas mínimas;
- no enviar contraseñas, tokens, contenido libre sensible, documentos, NIT, email u otra PII innecesaria;
- separar analítica de producto de auditoría;
- consentimiento/aviso se incorpora cuando la superficie y regulación aplicable lo requieran.

#### Mantenimiento y deuda técnica

- deuda técnica se registra en Issues con impacto y motivo, no como comentarios perdidos;
- prioridad por seguridad, riesgo de datos, confiabilidad, velocidad de entrega y costo de mantenimiento;
- dependencia crítica de seguridad: atención inmediata según explotabilidad/contexto;
- revisión de dependencias al menos mensual además de gates automáticos;
- revisión arquitectónica trimestral ligera para confirmar que el monolito modular sigue siendo adecuado;
- no refactorizar por preferencia estética si no reduce riesgo/costo o habilita producto.

#### Automatización operativa

Candidatos prioritarios a automatización a medida que exista superficie:

- smoke post-deploy;
- verificación de release identity;
- auditoría de dependencias;
- comprobación de backups;
- recordatorio/reporte de rehearsal;
- generación de sitemap;
- tareas programadas de mantenimiento;
- alertas sobre errores críticos;
- telemetría de tiempos Issue → producción.

Toda automatización debe ser idempotente cuando sea posible y operar con mínimo privilegio.

#### Detección de deriva de esquema post-deploy en shared hosting

Mientras Condor opere en Hostinger shared hosting, el cron/post-deploy **no ejecuta
migraciones productivas automáticamente**. Su responsabilidad es detectar si el
esquema está atrasado y fallar cerrado antes de regenerar la caché.

Contrato operativo:

- una sola corrida de post-deploy opera a la vez; el script usa un lock explícito y una segunda corrida concurrente se omite;
- un lock con propietario vivo nunca se recupera por antigüedad;
- un lock huérfano puede recuperarse de forma segura y un lock incompleto reciente obtiene un periodo de gracia antes de considerarse recuperable;
- el cron ejecuta `doctrine:migrations:up-to-date --env=prod --no-interaction` como comprobación read-only;
- si existen migraciones pendientes, termina con error accionable y **no** ejecuta `cache:clear` ni `cache:warmup`;
- una migración productiva requiere autorización humana explícita conforme a `AGENTES.md` §10 y se ejecuta como operación separada, nunca implícita desde cron, deploy o smoke;
- las migraciones autorizadas deben seguir siendo forward / expand-compatible; SQL destructivo, contracciones irreversibles, backfills riesgosos o cambios sin rollback permanecen fuera de cualquier automatización;
- cuando el esquema ya está al día, el orden permitido es **comprobar esquema → limpiar caché → calentar caché**;
- detectar esquema pendiente bloquea `VALIDATED_IN_PRODUCTION`; la identidad de release, el esquema migrado y el estado operativo reconciliado se registran como evidencias separadas;
- el observador de release nunca debe inferir que una migración fue aplicada solo porque versión/SHA coincidan.

Motivación operativa: V 0.1.20 demostró que Hostinger podía servir código nuevo
mientras el esquema del storefront seguía atrasado, produciendo HTTP 500. El
post-deploy debe hacer visible esa deriva sin convertir una mutación productiva
en una operación automática. La corrección del esquema requiere autorización y
ejecución separadas, seguida por el mismo smoke real que detectó el incidente.

#### Revisión periódica de seguridad

- seguridad se valida por slice, no solo en una auditoría anual;
- dependencia/vulnerability scanning en CI;
- revisión trimestral del baseline de amenazas cuando el producto ya maneje datos reales;
- revisar de nuevo ante: nuevo tipo de integración, pagos, uploads ampliados, autenticación móvil, cambios de tenancy o migración de infraestructura;
- findings se registran y priorizan con evidencia; no se silencian solo para “pasar” herramientas.

#### Portabilidad operativa

- logging, media, email, alertas, analytics y jobs se consumen mediante adapters/contratos cuando sean externos;
- cambiar Hostinger por AWS debe cambiar principalmente adapters/configuración/infraestructura;
- no se introduce dependencia de un servicio administrado hasta que resuelva una necesidad real medible.

La Fase 8 queda **definida como baseline operativo**. Sus ítems permanecen activos en el roadmap únicamente cuando falte implementación, medición o evidencia real.


### D-045 — IndexNow y Google Tag Manager por tenant

Condor incorpora dos integraciones configurables por tenant para sus superficies públicas. Ambas deben respetar aislamiento multi-tenant, dominios personalizados y la separación entre analítica del producto Condor y herramientas del cliente.

#### IndexNow

- Condor dispondrá de un adapter/servicio de IndexNow desacoplado del CMS, catálogo y e-commerce;
- la integración opera por tenant y canal público;
- las URLs notificadas se construyen usando el dominio público efectivo del tenant conforme a D-044: se usa el dominio personalizado únicamente cuando está verificado y marcado como primario; en cualquier otro caso, se usa la URL Condor correspondiente;
- se notifican cambios indexables por publicación, actualización relevante, cambio de URL y retiro/despublicación;
- soportar envío individual y por lote;
- la clave canónica de una operación IndexNow combina tenant, canal, URL pública efectiva, tipo de notificación y revisión/versionado del contenido cuando aplique;
- dos operaciones con la misma clave canónica son equivalentes para deduplicación, independientemente de si se enviaron de forma individual o dentro de un lote;
- un cambio de URL genera operaciones independientes para la URL anterior y la nueva cuando el protocolo/estado indexable lo requiera; no se colapsan por compartir el mismo recurso lógico;
- persistir como mínimo el estado de la operación, último intento, número de intentos y resultado; estados terminales exitosos no se reenvían salvo un replay explícito o una nueva revisión del contenido;
- los reintentos son acotados y solo aplican a estados reintentables; un replay explícito conserva trazabilidad y crea una nueva ejecución sin alterar la identidad de la operación original;
- una falla de IndexNow no bloquea la publicación del contenido: se registra el estado/último intento y se reintenta según política;
- claves/tokens se almacenan fuera del código y se aíslan por tenant/dominio cuando el protocolo lo requiera;
- nunca enviar rutas privadas, administración, login, borradores, contenido `noindex` ni URLs pertenecientes a otro tenant;
- la primera implementación puede ser síncrona o mediante cron/job acotado compatible con Hostinger; el contrato debe permitir migrar luego a cola/background processing;
- toda llamada síncrona a IndexNow debe usar timeout por solicitud y un presupuesto total acotado dentro de la petición de publicación; los valores concretos pertenecen a la política operativa;
- los reintentos deben ejecutarse preferiblemente fuera de la petición mediante cron/job; si temporalmente permanecen síncronos, deben existir límites explícitos de intentos, tiempo total y backoff para que IndexNow nunca convierta una publicación local válida en una espera no acotada;
- el envío debe ser idempotente desde la perspectiva de Condor.

#### Google Tag Manager por customer/tenant

- cada tenant puede configurar su propio **Google Tag Manager Container ID**;
- GTM es opcional y permanece desactivado por defecto;
- el Container ID se guarda como configuración del tenant/canal, nunca hardcodeado en Twig, React o archivos de despliegue;
- el Container ID aceptado usa la gramática exacta `GTM-[A-Z0-9]+`, sin espacios; se normaliza eliminando espacios exteriores y convirtiendo a mayúsculas antes de validar;
- valores que no cumplan esa gramática se rechazan antes de persistirse o activarse; ejemplos válidos: `GTM-ABC123`, `GTM-7XYZ9`; ejemplos inválidos: `UA-12345`, `gtm abc`, `GTM-`, `GTM_ABC123`;
- el snippet se inyecta únicamente en superficies públicas del tenant/canal donde esté habilitado;
- GTM del cliente **no se carga en administración/backoffice**;
- el mismo tenant conserva su GTM cuando se accede mediante su dominio Condor o su dominio personalizado, según la configuración del canal;
- aislamiento obligatorio: un tenant nunca puede recibir el Container ID ni scripts configurados por otro;
- la configuración se expondrá en administración cuando exista el módulo de sitio/integraciones;
- la integración debe poder condicionarse por consentimiento/cookies y futuras políticas de privacidad sin reescribir las plantillas;
- analítica interna de producto de Condor permanece separada del GTM del cliente;
- fallos o bloqueos del script de terceros no deben impedir que la página principal de Condor renderice su contenido esencial.

#### Seguridad y pruebas

- CSP debe evolucionar de forma explícita para permitir únicamente los orígenes necesarios cuando GTM esté activado, sin abrir políticas globales indiscriminadas;
- las pruebas deben verificar aislamiento entre tenants, habilitado/deshabilitado, dominio Condor vs personalizado y ausencia de GTM en superficies privadas;
- IndexNow debe probar que solo genera URLs públicas del tenant activo y que excluye contenido no indexable;
- las pruebas de IndexNow deben cubrir dominio personalizado verificado y primario vs URL Condor, publicación, actualización relevante, cambio de URL, retiro/despublicación indexable, envíos individuales y por lote, deduplicación, reintentos acotados, idempotencia y replay explícito;
- ninguna de las dos integraciones puede exponer secretos en HTML, logs o respuestas públicas más allá de identificadores públicos que el protocolo requiera.

### D-046 — Propietario único de plataforma y administración delegada

Condor separa de forma estricta la propiedad global de la plataforma de cualquier rol administrativo de tenants, sedes o módulos.

Reglas:

- existe **una sola cuenta propietaria de plataforma** en toda la instalación de Condor;
- el propietario usa el rol global `ROLE_PLATFORM_OWNER`, independiente de membresías, roles y permisos configurables de los tenants;
- `/adminpl0n3r` es una superficie exclusiva del propietario de plataforma y exige `ROLE_PLATFORM_OWNER` en backend;
- los demás administradores se autentican y operan por `/admin`; sus facultades se limitan al tenant, sede, módulo o función que les corresponda y nunca equivalen a propiedad de plataforma;
- el rol legado `ROLE_SUPER_ADMIN` no autoriza `/adminpl0n3r` y se elimina de la cuenta propietaria al migrarla al rol nuevo;
- el propietario puede operar sin membresía de tenant; esa excepción no se hereda a otros administradores;
- la unicidad del propietario es un **invariante de base de datos**, no solo una comprobación de aplicación: un registro singleton bloqueable serializa el aprovisionamiento y evita carreras TOCTOU;
- el aprovisionamiento soportado es idempotente para la misma cuenta y rechaza atómicamente cualquier intento de definir una cuenta propietaria distinta;
- correo, contraseña y demás credenciales reales del propietario permanecen fuera del repositorio y de documentación pública;
- una futura transferencia de propiedad deberá ser una operación explícita, auditada y diseñada como tal; nunca se implementará simplemente concediendo `ROLE_PLATFORM_OWNER` a una segunda cuenta.

### D-047 — Diagnóstico seguro y compartible de errores

Condor debe permitir investigar fallos de producción sin convertir logs internos en una superficie pública ni depender de que el propietario copie manualmente información sensible.

Reglas:

- todo fallo interno HTTP 5xx obtiene un **error ID** y conserva el `request_id`/correlation ID de la solicitud;
- el incidente persistido contiene únicamente contexto técnico acotado: timestamp UTC, status HTTP, método, nombre de ruta, clase de excepción, mensaje sanitizado, fingerprint, versión/SHA de release y stack acotado sin argumentos;
- no se almacenan headers, cookies, cuerpos de request, query strings completas, passwords, tokens, authorization headers, DSN, variables de entorno ni PII innecesaria;
- paths del stack se normalizan a rutas relativas al proyecto o basename; nunca se publica la ruta absoluta del servidor;
- el propietario puede inspeccionar incidentes desde `/adminpl0n3r/diagnosticos`;
- compartir un diagnóstico es una acción explícita del propietario y genera un token criptográficamente aleatorio de 256 bits, almacenado únicamente como hash;
- el enlace compartido es de solo lectura, expira a los **30 minutos**, puede revocarse y responde con `Cache-Control: no-store`, `X-Robots-Tag: noindex,nofollow` y `Referrer-Policy: no-referrer`;
- la vista compartida expone exclusivamente el payload sanitizado necesario para depuración; nunca el log crudo ni contexto de autenticación;
- tokens inválidos, vencidos o revocados responden como recurso no disponible sin revelar si alguna vez existieron;
- la retención inicial de incidentes es **14 días** y puede depurarse mediante comando/cron acotado; ningún proceso residente es requisito;
- si la persistencia del incidente falla, Condor debe seguir entregando una respuesta 500 segura con referencia y usar el log de runtime solo como fallback, sin filtrar el error original al cliente;
- el mecanismo es un adapter operativo compatible con Hostinger y podrá migrar posteriormente a un proveedor de observabilidad sin cambiar el contrato de seguridad.

Regla de depuración para agentes:

- ante un 5xx reportado, buscar primero el error ID y, cuando el propietario lo autorice, usar el enlace temporal sanitizado como evidencia principal;
- no solicitar contraseñas, secretos ni dumps/logs crudos cuando el diagnóstico compartible sea suficiente;
- una referencia o enlace diagnóstico no equivale por sí sola a autorización para mutar producción.


### D-048 — Administración unificada y contexto operativo del propietario de plataforma

Condor mantiene una sola base administrativa. La administración de tenants y el centro de control del propietario de plataforma comparten shell, design system, componentes y módulos siempre que representen la misma capacidad de negocio. El propietario de plataforma obtiene capacidades adicionales; no una segunda aplicación duplicada.

Reglas:

- `/admin` continúa siendo la entrada administrativa ordinaria para usuarios de tenants y `/adminpl0n3r` continúa reservado a `ROLE_PLATFORM_OWNER`;
- `/adminpl0n3r` debe extender la experiencia administrativa común con módulos globales exclusivos de plataforma, no reemplazarla por un producto paralelo;
- módulos, tablas, formularios, navegación y patrones de interacción compartidos se implementan una sola vez y se reutilizan en ambos contextos;
- el propietario puede seleccionar tenant y, cuando aplique, sede, para entrar en un **modo de contexto administrativo** que use la misma superficie funcional del administrador del cliente;
- cambiar de contexto no cambia la identidad autenticada ni elimina `ROLE_PLATFORM_OWNER`; no se implementa como suplantación silenciosa de otro usuario;
- toda autorización sigue siendo server-side y cada operación contextual conserva suficiente trazabilidad para identificar al actor real y al tenant/sede sobre los que actuó;
- mientras exista un contexto de tenant/sede activo, la interfaz muestra una **barra de contexto persistentemente visible** con tratamiento cromático distintivo, tenant/sede actual, indicación inequívoca del modo y una acción directa para volver al centro de control global;
- el tratamiento visual del centro de control global puede ser más avanzado, tecnológico y orientado a observabilidad/operación, pero conserva el mismo lenguaje visual, tokens y primitives del sistema de diseño;
- los dashboards globales de plataforma pueden combinar salud del sistema, tenants, seguridad, observabilidad, operaciones, configuración interna y métricas agregadas que no corresponden a un administrador de tenant;
- una futura capacidad para “ver exactamente como un usuario específico” se considera una función de impersonación separada y deberá diseñarse de forma explícita, auditada y revocable.

#### Navegación seccionada, no acumulación en la página inicial

El centro de control (`/adminpl0n3r`) es un resumen ejecutivo liviano, no el contenedor de todas las capacidades de plataforma. A medida que se desarrollen nuevas capacidades administrativas:

- cada capacidad funcionalmente distinta (empresas, staff, diagnósticos, catálogo futuro, etc.) recibe su **propia ruta y entrada de menú** en `AdminShell`, en vez de agregarse como un panel más apilado en la página inicial;
- la página inicial conserva solo métricas/resumen de alto nivel (salud del sistema, señales agregadas) y enlaces de entrada a cada sección;
- esta regla aplica igual a `/admin` (administración de tenant) cuando su navegación empiece a crecer más allá de lo trivial;
- mover un panel existente a su propia sección es una refactorización de navegación de bajo riesgo (reutiliza componentes y contratos ya construidos) y no requiere una decisión de producto nueva cada vez — solo seguir esta regla.

#### Progreso funcional visible

El desarrollo administrativo evita acumular backend útil durante largos periodos sin una representación visible para el propietario. Cuando un vertical slice alcance un contrato suficientemente estable y exista una representación segura:

- se expone progresivamente una vista mínima real en administración, incluso si al principio es read-only;
- no se crean controles falsos, placeholders que aparenten funcionar ni estados que oculten que una capacidad sigue incompleta;
- cuando la capacidad sea compartida por tenant y plataforma, la primera superficie reutilizable debe servir tanto a `/admin` como a `/adminpl0n3r` mediante permisos y contexto;
- seguridad, aislamiento multi-tenant, autorización en backend y calidad automática siguen siendo requisitos de entrega y no se degradan para mostrar progreso antes;
- cada slice debe dejar al propietario una forma proporcional de inspeccionar su avance desde el frontend cuando hacerlo sea técnicamente útil y seguro.



### D-049 — Storefront público por slug y dominio personalizado

Condor sirve una única experiencia pública por tenant, accesible tanto desde una ruta de plataforma como desde uno o más dominios personalizados verificados. El dominio personalizado no crea una copia del sitio ni una segunda aplicación.

#### Identidad pública del tenant

- cada tenant conserva un `slug` único y estable para su entrada bajo Condor, por ejemplo `https://www.condorapp.com.co/<slug>`;
- un dominio personalizado del cliente puede servir exactamente el mismo storefront sin redirección visible hacia Condor;
- la resolución se centraliza en un `TenantResolver` o abstracción equivalente;
- el resolver identifica tenant por **host personalizado verificado** o, cuando el host corresponde a Condor, por **slug de ruta**;
- un host desconocido falla cerrado y nunca selecciona un tenant por aproximación;
- resolver tenant no sustituye filtros tenant-scoped ni permisos: el aislamiento sigue siendo obligatorio en todas las consultas y mutaciones.

#### Modelo de dominios

Los dominios personalizados se modelan como datos persistentes de Condor y no como configuración hardcodeada.

La entidad `TenantDomain` o equivalente debe poder representar:

- tenant propietario;
- host normalizado y único globalmente;
- tipo de dominio: personalizado, plataforma o futuro subdominio;
- estado operacional: `pending_verification`, `verified`, `active`, `failed`, `disabled`;
- indicador de dominio primario;
- mecanismo/token de verificación cuando corresponda;
- timestamps de alta, verificación y última comprobación;
- diagnóstico de fallo sanitizado, sin exponer secretos ni datos sensibles.

Un mismo host no puede pertenecer simultáneamente a dos tenants activos.

El primer cliente real y los siguientes usan exactamente el mismo flujo de registro y resolución; no se admiten excepciones por cliente codificadas en rutas, configuración o plantillas.

#### Conexión del dominio

El flujo inicial de conexión es:

1. registrar dominio en Condor;
2. normalizar y validar sintaxis;
3. mostrar instrucciones de apuntamiento compatibles con el proveedor del cliente;
4. comprobar que el dominio llega a la infraestructura esperada;
5. verificar control/propiedad cuando sea necesario;
6. comprobar HTTPS/TLS;
7. marcar el dominio como `active`;
8. empezar a servir el storefront del tenant bajo ese host.

El DNS no apunta a una ruta `/slug`; apunta el host del cliente a la infraestructura de Condor y el tenant se resuelve desde el `Host` HTTP validado.

Condor no modifica DNS del cliente automáticamente salvo que exista una integración futura explícitamente autorizada.

#### Host y seguridad

- el header `Host` solo se acepta cuando coincide con un dominio registrado o con hosts propios conocidos de Condor;
- proteger generación de URLs absolutas, redirects, canonical, emails y callbacks frente a Host Header Injection;
- retirar/desactivar un dominio debe cortar inmediatamente su capacidad de seleccionar tenant;
- admin, login y APIs internas no se exponen automáticamente bajo dominios de storefront;
- un dominio personalizado nunca otorga permisos adicionales;
- la configuración DNS/TLS es una transición operativa separada del merge de código;
- el dominio no se declara listo hasta que apuntamiento, propiedad cuando aplique y HTTPS tengan evidencia suficiente.

#### Dominio primario, SEO e integraciones

Cada storefront tiene una URL pública primaria efectiva.

Cuando un dominio personalizado activo sea el primario, debe usarse como base para:

- `canonical`;
- sitemap;
- Open Graph;
- URLs absolutas;
- IndexNow;
- feeds y enlaces públicos.

La ruta bajo `condorapp.com.co/<slug>` puede mantenerse como fallback, pero el producto debe evitar contenido duplicado con canonical consistente y, cuando corresponda, redirección 301 controlada. Nunca redirigir antes de que el dominio personalizado esté comprobado como estable.

#### Administración

El Admin del tenant debe poder ver progresivamente:

- dominio configurado;
- estado de verificación;
- instrucciones de conexión;
- último chequeo y error sanitizado;
- acción de reintento;
- dominio primario cuando exista más de uno.

El Super Admin debe poder inspeccionar globalmente:

- tenant ↔ dominio;
- estado DNS/HTTP/TLS;
- última verificación;
- errores sanitizados;
- desactivación/revocación por seguridad.

#### Storefront mínimo inicial

Para desbloquear clientes reales antes de completar todo el e-commerce, Condor puede entregar un storefront mínimo pero real:

- render server-side con Twig/Symfony;
- identidad básica del negocio;
- datos reales del tenant;
- resolución por slug y por dominio;
- 404/host desconocido fail-closed;
- misma vista/tenant desde ambas entradas;
- pruebas negativas de aislamiento entre tenants;
- base preparada para catálogo, productos, SEO y e-commerce sin rehacer la resolución de tenant.

#### Hosting y portabilidad

Mientras Condor opere en Hostinger shared hosting se debe confirmar la capacidad real para asociar múltiples dominios al mismo document root y la emisión/renovación de TLS para dominios adicionales.

La asociación dominio → tenant permanece en Condor y no se codifica como una regla propietaria del hosting, de forma que una futura migración a AWS u otra infraestructura no requiera rediseñar el producto.


### D-050 — Staff de plataforma, invitaciones y notificaciones configurables

Condor distingue de forma explícita entre **propietario de plataforma**, **staff de plataforma** y **usuarios de tenant**.

#### Staff de plataforma

- el propietario conserva `ROLE_PLATFORM_OWNER` como autoridad global única;
- el staff interno de Condor usa `ROLE_PLATFORM_STAFF` y no necesita membresía de tenant;
- un miembro de staff puede recibir alcance global o sobre tenants concretos;
- los permisos se expresan inicialmente como **tenant + módulo + acciones CRUD**;
- los grants son explícitos, acumulativos solo cuando no introducen ambigüedad y siempre se validan server-side;
- ningún grant de staff implica suplantación de usuarios del cliente;
- toda operación sensible conserva actor real, tenant objetivo y contexto suficiente de auditoría;
- los permisos de staff y los roles internos del tenant son modelos separados.

#### Invitaciones y activación

- cuentas nuevas se crean inactivas y se activan mediante invitación de un solo uso;
- nunca se envían contraseñas por correo;
- el token bruto solo existe durante la emisión/entrega; persistencia guarda exclusivamente un hash irreversible;
- las invitaciones tienen expiración, revocación, reemisión auditada y consumo atómico;
- activación y reenvío aplican rate limiting;
- la persona invitada define su propia contraseña;
- recuperación de contraseña puede reutilizar primitives técnicas, pero usa propósito y token separados.

#### Correo transaccional

- el envío se realiza mediante un **contrato/adaptador**; identidad, dominio y aplicación no dependen de Hostinger ni de un proveedor SMTP concreto;
- remitente, URL base pública y credenciales pertenecen a configuración/secretos de entorno, nunca al dominio ni al código;
- las URLs absolutas de email se construyen desde una base confiable configurada, nunca desde el header `Host` de una petición;
- las notificaciones ordinarias pueden usar una **outbox persistente** antes de conectar un transporte real; esto permite reintentos, auditoría y cambio de proveedor sin rehacer los casos de uso;
- una outbox genérica **nunca persiste tokens de activación, recuperación ni otros secretos en texto plano**;
- el email sensible de invitación se entrega mediante un puerto transaccional mientras el token bruto sigue únicamente en memoria; si en el futuro se requieren reintentos durables de ese mensaje, deberá existir un sobre cifrado explícito con clave fuera de la base de datos o un mecanismo seguro regenerable, sin debilitar el hash canónico de la invitación;
- un fallo del proveedor debe quedar diagnosticado de forma sanitizada y permitir reemisión controlada de la invitación, sin reutilizar el token anterior;
- SPF, DKIM, DMARC, rebotes y reputación pertenecen a la transición operativa del proveedor de correo, no al núcleo de identidad.

#### Notificaciones

Condor modela notificaciones a partir de **eventos de aplicación** y canales intercambiables.

Canales iniciales:

- `in_app`;
- `email`.

Preferencias:

- se resuelven por usuario + evento + canal;
- eventos opcionales pueden habilitarse/deshabilitarse;
- eventos obligatorios de seguridad/operación ignoran una preferencia que pretenda silenciarlos;
- inicialmente son obligatorios como mínimo: invitación de cuenta y recuperación de contraseña;
- añadir SMS, push, WhatsApp u otro canal futuro no modifica los casos de uso que producen el evento.

La persistencia debe permitir distinguir estado pendiente, entregado y fallido/reintentable cuando el canal implique entrega asíncrona.

#### Adaptabilidad

Los requerimientos del primer cliente real —incluido su contexto textil/confección— no se hardcodean en el núcleo. Capacidades comunes evolucionan como módulos generales; variaciones por empresa son configuración; particularidades sectoriales se aíslan como extensión opcional.

#### UX progresiva

`/adminpl0n3r` debe hacer visible progresivamente:

- empresas;
- staff de plataforma;
- estado de invitación;
- matriz cliente → módulo → CRUD;
- creación básica de tenant reutilizando onboarding;
- preferencias de notificación cuando el backend correspondiente sea estable.

No se muestran acciones falsas ni controles sin contrato server-side real.


### D-051 — Integraciones mediante adaptadores

Toda integración con un proveedor externo (correo transaccional, pagos, webhooks salientes, etc.) se modela como:

- una interfaz en `Application/` que expresa el contrato de negocio, sin detalles del proveedor;
- una o más implementaciones concretas en `Infrastructure/`, cada una detrás de la misma interfaz;
- un adaptador `Null*`/`Log*` seguro como binding por defecto — nunca falla al resolverse, nunca hace una llamada de red real, y deja evidencia en logs de que la integración real no está configurada;
- el binding concreto se decide por configuración (DI), nunca condicionando lógica de negocio con `if` sobre qué proveedor está activo.

Precedente: `App\Application\Notification\TransactionalEmailGateway` + `App\Infrastructure\Notification\NullTransactionalEmailGateway` como binding por defecto. Un proveedor real (Mailer, API de un ESP) implementa la misma interfaz y sustituye el binding sin tocar el código que la consume.

Regla de PII: ningún adaptador registra en logs datos personales (destinatarios, nombres, tokens) — solo identificadores no sensibles (tipo de plantilla, tipo de evento).

## 7. Criterio de actualización

Una decisión debe incorporarse aquí cuando afecte de manera durable cómo se diseña, implementa, prueba, opera o evoluciona Condor.

El progreso, estado y orden de ejecución deben actualizarse en el [roadmap canónico — Issue #1](https://github.com/pl0n3r/Condor/issues/1), no duplicarse aquí.
