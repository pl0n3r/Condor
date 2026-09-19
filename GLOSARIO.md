# Condor — Glosario para seguimiento del proyecto

> Guía en lenguaje de negocio para entender los términos técnicos que aparecen en el README, roadmap, Issues y Pull Requests de Condor.
>
> Si un término técnico importante aparece de forma recurrente y no está explicado aquí, debe añadirse al glosario.

## Cómo leer el estado del proyecto

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **Condor App** | Nombre oficial y público del producto. | Se usa en marca, presentación y superficies públicas. |
| **Condor** | Nombre corto del proyecto. | Se usa en GitHub, documentación técnica y conversaciones de desarrollo. |
| **Roadmap** | Lista ordenada del trabajo planeado, en curso, bloqueado y terminado. | Vive en el Issue #1 y conserva el histórico tachando lo completado. |
| **Issue** | Tarjeta de trabajo o problema en GitHub. | Sirve para describir una tarea concreta, bug, mejora o investigación. |
| **ID de reserva** | UUID que identifica una sesión concreta de trabajo, incluso si varias sesiones comparten la misma cuenta. | Se genera al ejecutar `/tomar` y debe declararse en el PR. |
| **Transferencia de reserva** | Rotación explícita del ID para entregar la tarea a otra sesión. | `/transferir <UUID>` invalida el ID anterior y genera uno nuevo. |
| **Reserva de trabajo** | Marca que una tarea ya fue tomada por una sesión o agente. | Se obtiene comentando `/tomar` en un Issue disponible. |
| **Cola de trabajo** | Conjunto de tareas disponibles, reservadas, en revisión o bloqueadas. | GitHub muestra el estado de cada Issue mediante labels. |
| **Lock / bloqueo de coordinación** | Mecanismo que permite que solo una sesión sea propietaria de una tarea. | La rama `trabajo/issue-N` se crea de forma atómica y actúa como lock. |
| **Fail-closed** | Ante una duda, el sistema mantiene la tarea bloqueada en vez de entregarla a dos personas/agentes. | Las reservas no expiran solas; deben liberarse explícitamente. |
| **Solapamiento** | Dos PR intentan modificar el mismo archivo al mismo tiempo. | CI lo detecta y obliga a coordinar antes del merge. |
| **PR / Pull Request** | Propuesta de cambio que se revisa antes de entrar al producto principal. | Es el paso normal antes de fusionar trabajo a `main`. |
| **Versión** | Número fácil de reconocer para identificar una entrega del producto. | El primer deploy será `v0.1.0`; después normalmente `v0.1.1`, `v0.1.2`, etc. |
| **Versión objetivo** | Versión que una entrega está preparando. | Puede ser `v0.1.0` aunque todavía no haya sido desplegada. |
| **Versión desplegada** | Última versión que realmente llegó al servidor de producción. | Solo cambia cuando existe evidencia de que Hostinger recibió esa release. |
| **Release** | Entrega identificable del producto. | Normalmente corresponde a una versión desplegable de Condor. |
| **Deploy / despliegue** | Proceso de llevar una versión del código al servidor donde funciona el producto. | El objetivo es `main → Hostinger`. |
| **Producción** | Entorno real usado por clientes o usuarios finales. | No se considera validado solo porque las pruebas automáticas pasaron. |
| **VALIDADO EN CÓDIGO** | Las pruebas y controles técnicos requeridos pasaron. | Todavía no significa que Hostinger tenga esa versión. |
| **DESPLEGADO** | La versión ya llegó al servidor. | No implica necesariamente que todas las funciones se hayan probado allí. |
| **VALIDADO EN PRODUCCIÓN** | La función fue comprobada realmente en el entorno productivo. | Es el nivel de evidencia más fuerte de una entrega. |
| **Bloqueo** | Algo externo o pendiente que impide continuar una tarea. | Se marca con ⛔ en el roadmap. |

## GitHub y control de cambios

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **Repositorio** | Carpeta central donde vive el código y su historial. | `pl0n3r/Condor`. |
| **Git** | Sistema que registra cada cambio realizado al proyecto. | Permite saber qué cambió, cuándo y recuperar estados anteriores. |
| **GitHub** | Plataforma donde alojamos el repositorio, Issues, PRs y automatizaciones. | Es la plataforma central de colaboración técnica de Condor. |
| **main** | Rama principal y oficial del proyecto. | El código fusionado allí representa la base canónica del proyecto. |
| **Rama / branch** | Copia de trabajo separada para desarrollar sin alterar directamente `main`. | Cada cambio normal se prepara en una rama enfocada. |
| **Commit** | Registro de un grupo concreto de cambios. | Debe tener un mensaje claro en español. |
| **SHA** | Identificador único de un commit. Es como una huella digital del código. | Permite saber exactamente qué código se validó o desplegó. |
| **Merge** | Incorporar una rama o PR a la rama principal. | Los merges a `main` se hacen de forma controlada. |
| **Squash merge** | Fusionar una PR dejando sus cambios como un solo commit limpio. | Es la estrategia predeterminada de Condor. |
| **Head** | Último commit de una rama o PR. | Los controles finales deben revisar el head exacto que se va a fusionar. |
| **Diff** | Comparación que muestra qué líneas/archivos cambiaron. | Ayuda a revisar el alcance real de una PR. |
| **Label** | Etiqueta para clasificar Issues o PRs. | Puede indicar seguridad, calidad, producto, infraestructura, etc. |
| **Milestone** | Agrupador de Issues/PRs alrededor de un objetivo o etapa. | Se usará cuando aporte una vista útil del avance. |

## Calidad, pruebas y automatización

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **CI / Integración continua** | Sistema automático que revisa el código cada vez que se propone o integra un cambio. | Vivirá en GitHub Actions. |
| **GitHub Actions** | Herramienta de GitHub que ejecuta automatizaciones. | Ejecutará CI, pruebas y otros controles. |
| **Gate / control** | Requisito que debe pasar antes de considerar un cambio listo. | Puede ser una prueba, análisis de seguridad o revisión automática. |
| **Preflight** | Revisión rápida inicial que decide qué controles necesita una PR. | Evita correr pruebas innecesarias y reduce tiempos. |
| **Throughput del CI** | Qué tan rápido completa el proyecto sus controles automáticos desde que empiezan hasta que terminan. | Condor lo mide después de cada CI para encontrar cuellos de botella sin ralentizar las entregas. |
| **Wall time** | Tiempo real de reloj transcurrido entre inicio y final de una ejecución. | Es la métrica principal para saber cuánto tarda el CI completo. |
| **Línea base de CI** | Referencia construida con tiempos recientes comparables. | Condor usa la mediana de hasta cinco ejecuciones exitosas anteriores del mismo tipo. |
| **Regresión de throughput** | Cuando el CI se vuelve significativamente más lento que su comportamiento reciente. | Se advierte cuando supera a la vez +25% y +15 segundos frente a la línea base. |
| **Check** | Resultado visible de un control automático en GitHub. | Puede aparecer como aprobado, fallido o pendiente. |
| **SonarCloud** | Herramienta que analiza calidad, mantenibilidad y ciertos riesgos de seguridad del código. | Se configurará como control automático. |
| **CodeRabbit** | Revisor automático de Pull Requests con IA. | Ya puede comentar PRs de Condor. |
| **Finding / hallazgo** | Problema o recomendación detectada por una revisión automática o humana. | Los hallazgos válidos se corrigen antes del merge. |
| **Test / prueba automatizada** | Comprobación programada para verificar que algo funciona como esperamos. | Protege contra errores que reaparezcan. |
| **Prueba de contrato** | Verifica una regla o comportamiento técnico estable. | Ejemplo: que una API devuelva siempre la estructura esperada. |
| **Prueba de integración** | Comprueba que varias partes funcionan bien juntas. | Ejemplo: PHP + MariaDB. |
| **E2E / End-to-End** | Prueba que recorre un flujo casi como lo haría un usuario real. | Se hará con Playwright y un usuario de pruebas aislado cuando exista login. |
| **Playwright** | Herramienta para automatizar navegadores. | Servirá para probar flujos reales de la interfaz. |
| **Chromium** | Motor de navegador usado por Chrome/Edge. | Será la cobertura principal de pruebas de navegador. |
| **WebKit** | Motor de navegador usado por Safari. | Se usará en pruebas dirigidas cuando el riesgo lo justifique. |
| **Regresión** | Error que reaparece después de que algo ya funcionaba. | Cuando sea razonable, un bug corregido debe ganar una prueba que impida su regreso. |
| **Smoke test** | Prueba corta para comprobar que lo esencial funciona después de un deploy. | Puede ser pública o autenticada cuando sea seguro. |
| **Clean as You Code** | Enfoque que exige alta calidad sobre el código nuevo sin bloquear el proyecto por toda la deuda histórica. | Será la orientación inicial de SonarCloud. |

## Infraestructura y funcionamiento

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **Frontend** | Parte visual con la que interactúa el usuario. | Inicialmente HTML, CSS y JavaScript. |
| **Backend** | Lógica que procesa datos y reglas del sistema. | Objetivo inicial: PHP 8.5. |
| **Base de datos** | Lugar estructurado donde se guarda información persistente. | Objetivo inicial: MariaDB/MySQL. |
| **MariaDB / MySQL** | Tecnología de base de datos relacional. | Guardará los datos del SaaS cuando definamos el modelo. |
| **PHP** | Lenguaje que ejecutará gran parte de la lógica del servidor. | Objetivo inicial: PHP 8.5. |
| **JavaScript** | Lenguaje utilizado principalmente para comportamiento interactivo de la interfaz. | Se mantendrán dependencias contenidas. |
| **Hostinger** | Proveedor donde se planea alojar producción. | Se usará inicialmente shared hosting. |
| **Shared hosting** | Alojamiento donde varios clientes comparten infraestructura del servidor. | Obliga a mantener una arquitectura compatible y sencilla de operar. |
| **Runtime** | Entorno donde el código se está ejecutando realmente. | Puede referirse al PHP/servidor disponible en Hostinger. |
| **API** | Forma estructurada para que distintas partes del sistema intercambien datos. | El frontend podrá consultar o modificar datos a través de APIs controladas. |
| **Endpoint** | Dirección concreta de una API para realizar una operación. | Ejemplo futuro: consultar usuarios o guardar una empresa. |
| **Observabilidad** | Capacidad de saber qué está pasando en el sistema mediante señales y evidencias. | Incluye estado de deploys, errores y salud del sistema. |
| **Log** | Registro técnico de eventos del sistema. | Nunca debe guardar contraseñas, tokens o secretos. |
| **Backup** | Copia de seguridad de información. | No basta con crearla: debe poder restaurarse. |
| **Restore / restauración** | Recuperar información desde un backup. | Se probará en un entorno seguro. |
| **Rollback** | Volver a una versión anterior si una entrega falla. | Debe considerarse en operaciones sensibles. |
| **Dependencia** | Librería o servicio externo que el proyecto utiliza. | Se mantendrán las necesarias y se revisarán riesgos. |

## Arquitectura y datos

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **Arquitectura** | Forma general en que se organiza el sistema y cómo se conectan sus partes. | Se definirá después de cerrar suficiente alcance funcional. |
| **MVP** | Primera versión útil con el mínimo necesario para entregar valor real. | Su alcance se definirá en la Fase 1. |
| **Vertical slice** | Función completa de principio a fin, no solo una capa aislada. | Ejemplo: interfaz + backend + base de datos + permisos + pruebas de un flujo. |
| **Multi-tenant / multiempresa** | Un mismo sistema atiende varias empresas manteniendo sus datos aislados. | Todavía debe definirse si Condor lo necesita. |
| **RBAC** | Sistema de permisos basado en roles. | Permitirá decidir qué puede hacer cada tipo de usuario. |
| **Migración de base de datos** | Cambio controlado a la estructura de datos. | Fusionar código no significa que la migración se haya ejecutado en producción. |
| **Transacción** | Grupo de cambios de datos que deben completarse todos o ninguno. | Protege la integridad de operaciones importantes. |
| **Idempotente** | Operación que puede repetirse sin producir resultados duplicados o dañinos. | Es deseable en migraciones y automatizaciones cuando sea posible. |
| **Schema / esquema** | Estructura de tablas, campos y relaciones de la base de datos. | Se diseñará con el dominio funcional. |

## Seguridad

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **Autenticación** | Comprobar quién es una persona. | Ejemplo: login. |
| **Autorización** | Comprobar qué puede hacer una persona autenticada. | Ejemplo: un rol puede ver pero no borrar. |
| **Sesión** | Estado que mantiene a un usuario identificado mientras usa el sistema. | Debe protegerse y tener reglas explícitas. |
| **Cookie** | Pequeño dato que el navegador guarda para mantener ciertas funciones, como una sesión. | Las cookies sensibles tendrán protecciones apropiadas. |
| **CSRF** | Ataque que intenta hacer que un usuario autenticado ejecute una acción sin querer. | Las operaciones mutables deben protegerse. |
| **XSS** | Ataque que intenta ejecutar código malicioso en el navegador mediante contenido no seguro. | Se evita escapando/sanitizando contenido correctamente. |
| **Rate limiting** | Límite de frecuencia para evitar demasiados intentos o abuso. | Especialmente importante en login y endpoints sensibles. |
| **Hash de contraseña** | Forma irreversible de guardar una contraseña de manera segura. | Nunca se guardan contraseñas en texto plano. |
| **Secreto / secret** | Credencial técnica sensible, como tokens o claves. | Nunca debe quedar expuesta en el repositorio. |
| **Token** | Valor que autoriza o identifica una operación/sesión. | Debe tratarse como información sensible cuando corresponda. |
| **Prepared statement / consulta preparada** | Forma segura de enviar datos a SQL sin mezclarlos con la consulta. | Reduce riesgo de inyección SQL. |
| **Inyección SQL** | Ataque que intenta manipular consultas a la base de datos. | Se previene con consultas preparadas y validación server-side. |
| **Mínimo privilegio** | Dar a cada usuario o proceso solo los permisos que realmente necesita. | Será un principio permanente de seguridad. |

## Versionado

| Término | Explicación sencilla | En Condor |
| --- | --- | --- |
| **SemVer / versionado semántico** | Convención de versiones tipo `MAYOR.MENOR.PARCHE`. | Condor empieza en `0.1.0`. |
| **Patch / parche** | Último número de la versión; normalmente identifica una entrega incremental. | `0.1.0 → 0.1.1`. |
| **Minor / menor** | Número intermedio; representa un hito más significativo antes de 1.0. | `0.1.x → 0.2.0` requiere una decisión deliberada. |
| **Major / mayor** | Primer número; indica una etapa grande de madurez/cambio. | `1.0.0` solo llegará por decisión explícita. |
| **Bump de versión** | Incrementar el número de versión. | Toda PR que vaya a desplegarse deberá llevar el bump correspondiente. |
| **Versión en título** | Etiqueta de tracking al final de un título de GitHub. No significa que esa versión ya esté desplegada. | Ejemplo: `feat: autenticación (V 0.1.0)`. |

---

## Regla de mantenimiento

Este glosario está pensado para personas de negocio y debe mantenerse **simple, correcto y útil**.

Cuando aparezca un término técnico nuevo de importancia en el README, roadmap o documentación visible para socios:

1. añadirlo aquí si puede generar confusión;
2. explicarlo sin depender de otros términos técnicos;
3. usar un ejemplo de Condor cuando ayude;
4. evitar definiciones académicas innecesarias;
5. actualizar una definición si el significado práctico dentro del proyecto cambia.
