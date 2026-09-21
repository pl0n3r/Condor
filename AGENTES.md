# Condor — manual operativo canónico para agentes

> **Punto de arranque obligatorio para ChatGPT, Codex y cualquier agente que trabaje en Condor.**
>
> Leer este archivo antes de modificar el proyecto. La continuidad debe poder reconstruirse desde GitHub y el repositorio, no desde memoria conversacional.
>
> Condor adopta prácticas maduras de ingeniería de BRVTAL cuando aplican, pero nunca copia automáticamente su lógica de negocio, módulos, rutas, esquema ni deuda histórica.
>
> **Identidad:** producto público **Condor App**; nombre técnico corto **Condor**.

## 1. Fuentes de verdad y precedencia

Cada fuente tiene una responsabilidad distinta:

- **Código fusionado en main + pruebas:** verdad de implementación.
- **AGENTES.md:** contrato operativo de desarrollo.
- **ESPECIFICACIONES.md:** decisiones durables de producto y arquitectura.
- **Issue #1:** único Roadmap canónico, acumulativo y cronológico.
- **Issues específicos:** alcance ejecutable, riesgos y criterios de aceptación.
- **Pull Requests:** cambio concreto y evidencia de validación.
- **README.md:** snapshot ejecutivo del deploy/candidato vigente, no historial.
- **GLOSARIO.md:** términos técnicos explicados para seguimiento de negocio.
- **docs/:** documentación especializada que no cabe razonablemente en las fuentes anteriores.

Si dos fuentes se contradicen:

1. comprobar el código y las pruebas del SHA relevante;
2. distinguir implementación actual de decisión durable pendiente;
3. respetar el Issue/PR activo cuando delimite trabajo todavía no fusionado;
4. corregir en la misma entrega la documentación que haya quedado obsoleta.

No usar AGENTES.md como Roadmap, changelog ni archivo de decisiones funcionales.

---

## 2. Arranque de sesión, autonomía y escalamiento

### Bootstrap obligatorio

Antes de escribir:

1. leer AGENTES.md completo;
2. obtener el SHA exacto actual de main;
3. revisar PRs abiertos y trabajo reservado;
4. revisar CI/checks del PR activo y del SHA relevante de main;
5. leer el Roadmap #1;
6. leer el Issue específico que corresponda;
7. consultar solo las especificaciones/documentos necesarios para ese frente;
8. comprobar si otro PR ya cubre el trabajo;
9. comprobar colisiones de archivos/estado mutable;
10. si main tiene un gate obligatorio roto por el repositorio, priorizar su causa antes de abrir trabajo dependiente.

### Autonomía

**DEFAULT:** resolver de forma autónoma operaciones rutinarias y reversibles que el repositorio permita deducir con seguridad.

**ESCALAR únicamente cuando haga falta:**

- credencial o permiso no disponible;
- decisión de producto genuinamente ambigua;
- operación irreversible/destructiva de producción;
- acceso a datos sensibles;
- acción protegida que requiera autorización explícita.

No detener el flujo para pedir confirmación sobre lectura de código, pruebas, commits, PRs, correcciones de CI o decisiones técnicas claramente deducibles.

---

## 3. Coordinación multiagente y paralelización

GitHub es el árbitro de la cola de trabajo.

### Reserva obligatoria

**OBLIGATORIO antes de implementar trabajo nuevo o retomar trabajo existente:**

1. revisar primero Issues con estado: disponible;
2. revisar también Issues reservados/en revisión relevantes para detectar trabajo inactivo y evitar abrir un frente duplicado;
3. si una reserva existente tiene actividad de los últimos 45 minutos, respetarla y elegir otro trabajo;
4. si lleva al menos 45 minutos sin actividad verificable, ejecutar /tomar sobre ese mismo Issue para intentar recuperarlo;
5. ejecutar /tomar normalmente sobre un Issue disponible cuando no exista trabajo previo reutilizable;
6. esperar una reserva válida y conservar el UUID publicado por el bot;
7. trabajar únicamente en la rama canónica trabajo/issue-N y reutilizar el PR existente cuando la recuperación lo indique.

La reserva válida crea o recupera el lock de trabajo sin duplicar la implementación. Abrir un Issue/PR nuevo para sustituir silenciosamente otro frente abandonado es el último recurso, no el flujo normal.

### Propiedad de sesión y recuperación por inactividad

- una reserva reciente protege el trabajo de otras sesiones aunque compartan la misma cuenta de GitHub;
- el UUID visible no autoriza por sí solo a continuar una sesión ajena;
- **la reserva no es eterna**: si no existe actividad verificable durante al menos 45 minutos, otro agente puede ejecutar /tomar y recuperar el mismo trabajo;
- cuentan como actividad reciente los commits de la rama, actividad del PR existente y comentarios humanos útiles del Issue; los comandos de coordinación no refrescan artificialmente la reserva;
- si no existe evidencia temporal suficiente, el coordinador falla de forma conservadora y no roba el trabajo;
- recuperar trabajo stale genera un UUID nuevo y conserva la rama canónica trabajo/issue-N;
- si ya existe un PR abierto para esa rama, se reutiliza y actualiza su metadata de reserva: **no se cierra ni se abre otro PR solo por cambio de agente**;
- si dos sesiones intentan recuperar simultáneamente una reserva stale, el marcador confiable más reciente arbitra la propiedad;
- un Issue bloqueado nunca se recupera automáticamente;
- nunca crear una rama alternativa para saltarse una reserva vigente;
- liberar con /liberar <UUID>;
- transferir explícitamente entre sesiones de la misma cuenta con /transferir <UUID>;
- /liberar-forzado queda como mecanismo excepcional del dueño del repositorio, no como flujo normal para trabajo simplemente inactivo.

### PR de una reserva

El PR debe:

- salir de trabajo/issue-N hacia main;
- incluir Closes #N;
- declarar el UUID activo de reserva;
- abrirse temprano, después del primer bloque lógico útil;
- evitar colisiones con archivos de otros PR abiertos.

Si existe colisión, repartir, serializar o sincronizar el trabajo. Nunca sobrescribir silenciosamente.

### Paralelización

**DEFAULT:** paralelizar lecturas, análisis, pruebas independientes y hasta 4 Issues reservados que no compartan archivos ni estado mutable.

**SERIALIZAR siempre:**

- escrituras al mismo archivo;
- ramas dependientes;
- migraciones/operaciones productivas;
- merges a main.

Mientras un gate externo procesa, avanzar solo trabajo independiente compatible. No iniciar una rama dependiente antes de validar el SHA exacto de main del bloque previo.

### Archivos globales

README.md, AGENTES.md, ESPECIFICACIONES.md, GLOSARIO.md y workflows se modifican solo cuando el Issue lo exige.

Una rama paralela normal no actualiza README prematuramente. El snapshot se sincroniza cuando el PR se convierte en el siguiente candidato serial de merge/deploy.

---

## 4. Contexto técnico y principios de implementación

### Referencia mínima

| Elemento | Contrato |
| --- | --- |
| Repositorio | pl0n3r/Condor |
| Rama canónica | main |
| Dominio | https://www.condorapp.com.co |
| Mercado/locale | Colombia / es-CO |
| Moneda por defecto | COP |
| Backend | PHP 8.5 + Symfony 7.4 LTS |
| Datos | MariaDB / MySQL-compatible + Doctrine |
| Admin | React + TypeScript + Vite |
| Público/SEO | Twig/Symfony SSR |
| Hosting inicial | Hostinger shared hosting |
| E2E | Playwright / Chromium |
| Calidad | GitHub Actions + SonarQube Cloud + CodeRabbit |

El detalle durable de arquitectura vive en ESPECIFICACIONES.md.

### Ingeniería

- construir vertical slices completos antes que abstracciones sin uso;
- servidor como autoridad final de validación/autorización;
- multi-tenancy como invariante testeada, nunca como filtro de UI;
- relaciones de negocio como datos estructurados;
- consultas parametrizadas/Doctrine;
- transacciones cuando varias escrituras deban ser atómicas;
- locking cuando la concurrencia pueda romper invariantes;
- migraciones explícitas, expand-compatible y seguras ante despliegues parciales;
- distinguir borrador/incompleto de publicado/activo cuando el dominio lo exija;
- no asumir que merge, deploy y migración son el mismo evento;
- secretos fuera del repositorio;
- evitar procesos Node permanentes y Docker como requisito de runtime mientras Hostinger shared hosting siga siendo la plataforma;
- mantener portabilidad y evitar acoplamiento innecesario a APIs propietarias del hosting.

### Frontend

El frontend evoluciona junto con cada slice, no al final.

- exponer progresivamente superficies reales cuando el backend tenga un contrato útil;
- compartir shell/componentes/contratos cuando Admin y propietario representen la misma capacidad;
- no simular funcionalidad con botones/placeholders falsos;
- mobile-first, accesibilidad y estados loading/empty/error/permission-denied reales;
- mejoras visuales pequeñas y acumulativas, no rediseños masivos desconectados;
- dirección pública sobria, corporativa y premium; Apple puede ser referencia de nivel de acabado, nunca plantilla ni identidad;
- evitar estética SaaS saturada, ruido visual y dependencias visuales que degraden SSR/SEO o rendimiento.

### Idioma

Usar español de Colombia en superficies controlables: UI, validaciones, documentación, Issues, PRs, commits, workflows visibles y textos de pruebas. Mantener inglés cuando traducir rompa precisión o compatibilidad técnica.

---

## 5. Seguridad, pruebas y calidad

### Seguridad no negociable

Cuando aplique:

- autenticación centralizada y autorización server-side;
- session ID regenerado en transiciones sensibles;
- cookies HttpOnly/Secure y SameSite apropiado;
- CSRF en mutaciones autenticadas;
- hashes modernos de contraseña;
- rate limiting en login/endpoints sensibles;
- validación y normalización server-side;
- escaping/sanitización por contexto;
- prepared statements/Doctrine;
- uploads validados por tipo, tamaño, ruta y autorización;
- logs sin passwords, tokens, secretos ni PII innecesaria;
- mínimo privilegio;
- backups con restauración demostrable.

Para diagnóstico 5xx, preferir el mecanismo sanitizado/compartible del producto. Un enlace de soporte debe ser temporal, read-only, revocable y omitir cookies, headers sensibles, request bodies, secretos y argumentos innecesarios del stack.

Un finding válido se corrige en la causa raíz; no se silencia para pasar una herramienta.

### Estrategia de pruebas

**Behavior-first y proporcional al riesgo:**

- unit/contract para invariantes puros;
- integration para persistencia, API, autorización y fronteras;
- Playwright para flujos reales;
- credenciales E2E aisladas y descartables, nunca datos reales;
- Chromium como cobertura primaria; WebKit solo donde el riesgo Safari/iOS lo justifique;
- una corrección determinista importante debe ganar una regresión cuando exista un límite estable que probar.

Evitar tests que solo reproduzcan estructura interna sin verificar comportamiento útil.

### Evidencia automática

CI, SonarQube Cloud y CodeRabbit forman un único sistema de evidencia.

- revisar el head exacto estable;
- si cambia el head, resultados anteriores no prueban el nuevo;
- validar findings contra código actual;
- corregir findings válidos y explicar descartes reales;
- no declarar un gate aprobado mientras procese;
- no fusionar con threads válidos pendientes;
- un gate omitido solo cuenta como success si el clasificador demuestra que no aplica.

---

## 6. CI-first: topología, retries y flujo de entrega

### Invariantes del CI

- Preflight corto y obligatorio.
- Selección de gates por exclusión segura.
- Ruta desconocida/configuración crítica/dependencias/workflows/clasificador => validación completa fail-safe.
- Gates independientes en paralelo.
- Timeout explícito por job.
- Check agregado estable Validar como contrato de branch protection.
- Dependencias desde lockfiles y entornos descartables.
- Acciones externas fijadas a SHA.
- Mínimo privilegio; no usar pull_request_target para ejecutar código no confiable.
- Caché solo donde acelere sin esconder estado.
- Evidencia útil en fallos; evitar artefactos de éxito sin valor.
- Cada resultado pertenece a un SHA concreto.
- Telemetría compara workloads equivalentes.

### Retry/autocuración

Reintentar solo operaciones externas, idempotentes y razonablemente transitorias, con intentos máximos, backoff y presupuesto de tiempo.

Ejemplos aceptables: descargas, registry/package manager, HTTP externo con timeout/408/425/429/5xx cuando repetir sea seguro.

**PROHIBIDO usar retry ciego para:**

- assertions/tests;
- lint/typecheck/análisis estático;
- sintaxis;
- migraciones/schema mismatch;
- autorización/permisos;
- contratos/invariantes;
- configuración determinista;
- findings de seguridad/calidad;
- smoke con versión/SHA/estado funcional incorrecto.

Fallo determinista: diagnosticar → corregir causa → añadir/ajustar regresión → prueba dirigida → revalidar gate.

### Bucle de entrega

**A. Preparar:** bootstrap §2 → reservar §3 → comprobar colisiones/base.

**B. Implementar:** cambio mínimo completo → regresiones → pruebas rápidas → commits lógicos → PR temprano.

**C. Estabilizar:** CI + coordinación + Sonar + CodeRabbit → corregir causas → head estable → volver a comprobar main/head/reserva/mergeability.

**D. Integrar:** squash merge serial → obtener SHA exacto de main → validar gates aplicables sobre ese SHA.

**E. Entregar:** observar deploy por separado → validar transición real → smoke real → actualizar Roadmap y especificaciones cuando corresponda.

No hacer commits cosméticos mientras una revisión automática útil procesa un head estable.

---

## 7. GitHub, versionado y releases

### Convenciones GitHub

Todo lo controlable por el proyecto se escribe en español cuando sea viable.

Ramas normales: exclusivamente trabajo/issue-N creadas por coordinación.

Commits concisos; prefijos convencionales feat/fix/docs/test/refactor/perf/infra pueden conservarse.

Todo título humano controlable de Issue, PR, Release, Milestone o equivalente termina exactamente en:

(V X.Y.Z)

No usar variantes de mayúsculas/formato.

### Versionado por deploy

Fuente canónica: config/version.php. package.json y metadata equivalente mantienen paridad cuando corresponda.

- cada deploy productivo identificable usa una versión humana;
- el incremento normal pre-1.0 es patch;
- no reutilizar una versión para dos deploys distintos;
- no saltar versiones deliberadamente sin razón;
- cambio de minor requiere hito deliberado;
- 1.0.0 requiere decisión explícita del usuario;
- versión humana y SHA Git son identidades complementarias;
- toda PR deploy-bound contiene su versión objetivo antes de gates finales.

Una PR documental/gobierno que no forme parte de un deploy productivo no obliga por sí sola a consumir versión; si efectivamente entra en un deploy distinto, aplica la regla general de versión por deploy.

### Estados de entrega

Usar con precisión:

- **IMPLEMENTADO:** existe en código.
- **VALIDADO EN CÓDIGO:** pasaron los gates requeridos.
- **DESPLEGADO:** producción recibió la release.
- **VALIDADO EN PRODUCCIÓN:** se comprobó comportamiento real.

CI verde o merge nunca equivalen automáticamente a producción.

---

## 8. README y snapshot de entrega

README.md representa solo el deploy/candidato operativo vigente.

Para un PR deploy-bound, cuando sea el próximo candidato serial:

- reemplazar el snapshot, no añadir changelog acumulativo;
- mostrar versión objetivo y última versión desplegada comprobada por separado;
- incluir Estado del deploy con tabla Señal | Estado | Evidencia;
- incluir SHA/base relevante;
- Qué se hizo;
- archivos del deploy;
- Validación;
- Qué sigue en AHORA / SIGUE / DESPUÉS o equivalente;
- Panorama general pendiente con enlace al Roadmap;
- flujo de entrega Mermaid cuando aplique;
- badges de CI/Sonar/observación configurados;
- enlaces a AGENTES.md, ESPECIFICACIONES.md y Roadmap #1.

Nunca actualizar Versión desplegada por CI o merge sin evidencia de deploy.

README no sustituye Roadmap, especificaciones, manual operativo ni historial Git.

---

## 9. Roadmap, documentación y comunicación humana

### Roadmap canónico

Issue #1 es el único Roadmap activo y se conserva cronológico, acumulativo y completo hasta al menos V 1.0.0.

- cuerpo: fases, orden, estado y ruta de ejecución;
- comentarios: hitos macro relevantes;
- hitos completados permanecen visibles y tachados;
- no crear un segundo Roadmap;
- no convertirlo en log de commits, retries, checks o findings menores;
- el detalle fino vive en Issue/PR/check/especificaciones;
- una instrucción explícita del usuario puede repriorizar el plan;
- después de un merge relevante, el Roadmap debe reflejar la realidad.

Un comentario de Roadmap merece existir cuando se entrega un slice/capacidad, cambia una decisión durable, se resuelve un incidente mayor, una versión cambia de estado significativo o cambia materialmente la prioridad.

Formato recomendado:

### Hito macro — resultado
- **Qué se logró:** capacidad/resultado.
- **Alcance:** límites relevantes.
- **Evidencia clave:** Issue/PR/SHA/versión cuando ayude.
- **Estado:** VALIDADO EN CÓDIGO | MERGED | DESPLEGADO | VALIDADO EN PRODUCCIÓN | BLOQUEADO.
- **Siguiente:** próximo bloque relevante.

### Comunicación en GitHub

Mantener detalle técnico útil, pero cada comentario/PR/Issue debe poder entenderse meses después sin reconstruir el chat.

Conectar de forma natural:

1. **qué cambió**;
2. **por qué importa**;
3. **dónde quedó reflejado**;
4. **qué habilita o afecta**.

Evitar frases telegráficas como “gates verdes” o “ajuste aplicado” sin contexto. Explicar acrónimos/estados cuando la audiencia pueda ser producto u operación. Referenciar Issue/PR/SHA/gate cuando aporte trazabilidad, no como lista de IDs.

Comunicación humana no significa micro-log: seguir reportando hitos macro.

### Documentación durable

- decisiones funcionales/arquitectónicas nuevas → ESPECIFICACIONES.md;
- términos técnicos importantes para negocio → revisar GLOSARIO.md;
- manual operativo cambia solo cuando cambia el modo de trabajar;
- historial de releases vive en Git/PRs/Roadmap, no aquí.

---

## 10. Producción y seguridad de transición

**PROHIBIDO ejecutar sin autorización explícita previa, y nunca de forma automática:**

- SQL destructivo;
- reset/seed de producción;
- borrado irreversible;
- rotación de secretos reales;
- cambio DNS/infra irreversible;
- operación con riesgo de interrupción sin rollback;
- migración productiva.

Escalar estos casos no equivale a autorización: después de escalar, esperar una aprobación explícita antes de ejecutar cualquier operación de esta lista.

Código desplegado, esquema migrado y estado operativo reconciliado son cosas distintas.

### Checklist de transición

Cuando el cambio toque esquema, roles, servicios, comandos, rutas, env/config, caché/container, provisioning/backfill o estado persistente, validar:

1. **Identidad:** versión + SHA + evidencia temporal esperada.
2. **Esquema:** Doctrine Migrations conocido; deploy no implica migración.
3. **Datos:** backfills/provisioning/singletons/roles/flags completos.
4. **Compatibilidad:** código nuevo tolera el estado previo o falla seguro y diagnosticable.
5. **Container/caché:** limpiar/warmup y comprobar descubrimiento cuando cambien servicios/rutas/comandos.
6. **Config externa:** variables/secrets necesarios existen sin imprimir valores.
7. **Superficies:** rutas, comandos y servicios esperados existen en runtime.
8. **Rollback:** preservar compatibilidad; usar expand → migrate/backfill → contract cuando aplique.
9. **Smoke:** público y autenticado aislado cuando corresponda.
10. **Invariantes:** comprobar el estado que el código realmente necesita.

El detector automático de transición es una señal mínima, no autoridad absoluta. Si el análisis del diff exige transición, un requerida=false automático nunca autoriza a omitirla.

Una transición incompleta bloquea VALIDADO EN PRODUCCIÓN.

---

## 11. Modo incidente

Ante fallo de producción o CI determinista:

1. separar código, deploy, esquema, configuración, caché/container y estado persistente como hipótesis independientes;
2. empezar con evidencia read-only;
3. hipótesis no equivale a causa raíz;
4. confirmar causa antes de mutar;
5. aplicar la corrección mínima autorizada;
6. repetir la misma superficie real que falló;
7. convertir causas deterministas en regresión/readiness check/regla de CI cuando sea razonable;
8. documentar el detalle en Issue/PR y solo el hito macro en Roadmap.

No pedir logs crudos si ya existe diagnóstico sanitizado suficiente. No reintentar un fallo determinista solo para intentar obtener verde.

Si el bloqueo es externo, documentarlo con precisión y continuar trabajo independiente compatible.

---

## 12. Handoff y mantenimiento

Antes de terminar o transferir una sesión:

- dejar Issue/PR con estado técnico suficiente para continuar sin el chat;
- conservar UUID de reserva o transferir/liberar correctamente;
- registrar bloqueos, findings pendientes y siguiente acción concreta;
- no dejar afirmaciones de producción sin evidencia;
- si cambió main, indicar SHA exacto relevante.

No usar ZIP como handoff, respaldo ni fuente de verdad. El trabajo vive en Git/PR; artefactos de Actions son evidencia efímera.

Actualizar AGENTES.md solo cuando cambien de forma durable:

- flujo de desarrollo/coordinación;
- gates;
- seguridad/testing;
- release/deploy;
- responsabilidades entre fuentes de verdad.

**Regla final:** un agente nuevo debe poder leer este manual, revisar el repositorio y el Roadmap #1, y continuar Condor sin necesitar ninguna conversación anterior.
