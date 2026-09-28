# Knowledge Gaps & Factory handoff v1

Issue #312 convierte handoffs de soporte sin evidencia suficiente en una señal minimizada y deduplicable. Este slice no persiste gaps, no crea tickets y no implementa un scheduler.

## KnowledgeGap

Un gap representa una pregunta que terminó como `unanswered` o `escalated`.

El dominio conserva únicamente:

- fingerprint estable derivado de una normalización transitoria de la pregunta;
- locale;
- módulo;
- scope;
- source/evidence refs;
- ventana observada;
- conteos agregados de unanswered/escalated.

La pregunta se normaliza de forma transitoria para deduplicación y **no se conserva en `snapshot()` ni en el agregado**. Antes de calcular el fingerprint se rechazan patrones evidentes de secretos, emails y teléfonos. El payload es cerrado y no admite campos arbitrarios como customer IDs, tokens o blobs de ticket.

La identidad del gap depende de:

- pregunta normalizada;
- locale;
- módulo;
- scope.

Los conteos y nuevas evidence refs no cambian el fingerprint.

## Agregación

`KnowledgeGap::aggregate()` agrupa por fingerprint y:

- suma `unanswered_count` y `escalated_count`;
- conserva primera y última observación;
- une y ordena `source_refs` y `evidence_refs`;
- mantiene orden determinista por fingerprint.

La agregación no infiere identidad de cliente ni reconstituye conversaciones completas.

## Factory WorkItem

`FactoryWorkItemAdapter` proyecta un gap agregado al **Work Origin Contract v1** publicado por Factory #269.

La salida usa:

- `origin_mode: automatic`;
- `origin_system: product`;
- `work_type: knowledge_documentation|content|product`;
- capabilities y roles explícitos;
- authority level declarado;
- priority class declarada;
- policy ref;
- evidence refs;
- `observed_at` ISO-8601;
- idempotency key derivada del fingerprint del gap.

El adapter no decide readiness, executor, provider, budget ni approval. Tampoco publica artículos. Factory valida el WorkItem y aplica readiness/Dispatcher V2 posteriormente.

La pregunta normalizada **no se conserva en el gap agregado ni se copia al WorkItem**; el handoff usa fingerprint y referencias de evidencia. Esto minimiza exposición y evita trasladar texto de soporte a la cola global.

## Idempotencia

El `work_id` y la `idempotency_key` derivan del fingerprint estable y el work type.

Por eso:

- observaciones repetidas no crean trabajo duplicado;
- aumentar los conteos no cambia la identidad del trabajo;
- agregar evidencia nueva conserva la misma unidad lógica;
- cambiar el tipo de trabajo produce una unidad distinta.

## Publication boundary

Un gap puede originar trabajo de documentación, contenido o producto, pero **no publica conocimiento**.

Publicar sigue requiriendo el lifecycle canónico de `KnowledgeArticle` de #310:

`draft → review → approved → published`

y cualquier authority/policy aplicable en el flujo que materialice esa transición.

## Fuera de alcance

- DB o migraciones;
- tickets/CRM persistidos;
- LLM provider;
- embeddings;
- UI administrativa;
- publicación automática;
- cambios de autoridad;
- ejecución de Factory;
- producción.
