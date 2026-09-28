# Knowledge Support Surfaces v1

Issue #313 expone la fuente canónica de Knowledge en superficies SSR y observabilidad read-only. No añade persistencia, proveedor LLM, voz ni publicación automática.

## Fuente canónica

KnowledgeController::canonicalArticles() es el registry in-process único de este slice. Cada entrada se materializa como KnowledgeArticle; FAQ, Help Center, ayuda contextual y admin consumen ese mismo conjunto.

Esta decisión es reversible: cuando exista un repositorio persistente de Knowledge, el registry puede sustituirse detrás de estas superficies sin cambiar KnowledgeArticle, KnowledgeRetrieval ni los contratos de UI.

No existe una segunda copia de pricing o capabilities en Knowledge.

## Superficies

### Público

Rutas: /ayuda y /faq.

Ambas usan exactamente el mismo retrieval: visibility public, locale es-CO, scope global, fuente KnowledgeArticle canónica y estado published + fresh.

La ruta /faq declara canonical hacia /ayuda para evitar duplicación SEO. El markup usa FAQPage, Question y Answer sobre la misma evidencia renderizada.

### Ayuda contextual

/admin/ayuda requiere autenticación por la política existente de ^/admin.

El servidor resuelve el tenant con CurrentTenantForUser y llama KnowledgeRetrieval con visibility customer, scope tenant:<id>, locale es-CO y el módulo validado.

El cliente nunca puede elevar visibility ni elegir otro tenant. Un módulo sin evidencia produce handoff, no una respuesta inventada.

### Observabilidad owner

/adminpl0n3r/conocimiento requiere ROLE_PLATFORM_OWNER y es read-only.

Muestra conteo por lifecycle, artículos stale o sin freshness vigente, gaps persistidos/unanswered y usage por canal.

En este slice no existe persistencia o telemetría de gaps/usage. Por eso gaps/unanswered son cero y usage muestra not_observed o not_enabled. No se fabrican métricas.

## Estados UX

- loading: no aplica al contenido inicial porque las superficies son SSR; no existe shell vacío esperando JavaScript.
- empty: Help Center entra en handoff sin evidencia y admin muestra un estado vacío para gaps.
- error: un request inválido retorna 400; errores internos siguen el manejo Symfony existente.
- permission denied: /admin/ayuda usa autenticación ^/admin y /adminpl0n3r/conocimiento exige platform owner.

## Handoff y futura voz

La UI no genera una respuesta con IA. Renderiza el resultado support-context-v1 de #311: ready muestra evidencia y handoff explica que no existe evidencia suficiente y orienta a soporte humano.

La misma capa puede ser consumida por futura voz sin cambiar el límite de confianza.

## Seguridad y privacidad

- visibility y tenant no vienen de query;
- module solo puede restringir resultados;
- customer, staff y public permanecen separados por KnowledgeRetrieval;
- no se agregan PII, mensajes privados, tickets ni secretos;
- admin no publica, edita ni cambia lifecycle;
- no hay DB, migraciones ni side effects externos.

## Verificación

AC-01: tests HTTP comparan /ayuda y /faq sobre el mismo knowledge id.

AC-02: tests HTTP prueban tenant scope, customer visibility y autenticación.

AC-03: tests owner prueban lifecycle, stale, gaps, unanswered y usage explícito.

AC-04: tests públicos verifican canonical SSR y ausencia de pricing/capabilities hardcodeados.

AC-05: Playwright navega a un módulo sin evidencia y verifica handoff visible.

AC-06: todas las superficies consumen KnowledgeArticle + KnowledgeRetrieval y el contrato support-context-v1, sin provider específico.
