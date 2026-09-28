# Knowledge Support Retrieval v1

Issue #311 añade la capa Application que consume el dominio canónico de Knowledge de #310. Este slice no implementa búsqueda semántica, embeddings, proveedor LLM, UI ni persistencia.

## Contrato

`KnowledgeRetrieval::retrieve()` recibe:

- una lista de `KnowledgeArticle` ya normalizados;
- un request server-side con `visibility`, `locale`, `module`, `scope`, `evidence_confidence` y `minimum_sources`;
- el instante de evaluación.

La salida es channel-agnostic y siempre usa `channel_contract: support-context-v1`.

Estados:

- `ready`: existe evidencia autorizada suficiente;
- `handoff`: confidence o cantidad de evidencia no alcanzan el umbral.

Este componente **no redacta una respuesta final**. Solo selecciona y empaqueta evidencia autorizada para que chat, Help Center o futura voz reutilicen el mismo límite de confianza.

## Elegibilidad

Un artículo entra al paquete solo si:

1. está `published`;
2. su freshness sigue vigente en el instante evaluado;
3. su `visibility` y `audience` coinciden exactamente con la visibility solicitada;
4. su locale coincide;
5. declara el módulo solicitado;
6. su scope es `global` o coincide exactamente con el tenant solicitado.

No se infiere tenant por sesión, texto, nombre del usuario ni contenido del artículo.

El estado `approved` es una etapa de lifecycle, no autorización suficiente para retrieval: el conocimiento debe haber pasado a `published`.

## Boundaries

- `public` solo se evalúa con scope `global`;
- customer y staff no se mezclan entre sí;
- un artículo de `tenant:acme` nunca aparece para `tenant:other`;
- conocimiento global del mismo canal puede reutilizarse dentro de un tenant;
- stale, draft, review, approved, superseded y archived quedan fuera del paquete.

Estos límites son server-side. El cliente o canal no decide qué conocimiento puede ver.

## Evidencia y handoff

Cada evidencia conserva:

- `knowledge_id` y versión;
- `source_ref`;
- fingerprint SHA-256 del snapshot normalizado;
- `reviewed_at` y `stale_after`;
- scope, visibility, locale y módulo.

La evidencia se ordena determinísticamente.

`evidence_confidence` es una señal explícita del pipeline server-side. `insufficient` o `unknown` producen handoff aunque existan artículos. También hay handoff cuando `evidence_count < minimum_sources`.

El handoff conserva la evidencia encontrada para diagnóstico, pero nunca la presenta como respuesta suficientemente soportada.

## Seguridad y privacidad

Este slice no recibe tokens, customer payloads, tickets, mensajes privados ni credenciales. Consume solo `KnowledgeArticle` normalizado y contexto mínimo de retrieval.

No abre endpoints ni llama providers externos. La autorización HTTP, identidad del actor y construcción server-side de `evidence_confidence` pertenecen a slices posteriores.

## Determinismo

Con los mismos artículos normalizados, request e instante de evaluación, el resultado y orden de evidencia son idénticos. No hay randomness, ranking aprendido ni heurística semántica.
