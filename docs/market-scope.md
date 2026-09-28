# Condor Market Scope v1

Issue #324 mantiene a Condor Colombia-first y convierte esa dirección en una configuración reemplazable, no en una regla universal del núcleo.

## Fronteras

Market Scope responde dónde Condor pretende operar comercialmente.

No es lo mismo que:

- entidad legal;
- jurisdicción aplicable;
- locale o idioma;
- moneda;
- timezone;
- infraestructura o región de hosting;
- provider de pagos.

Estas dimensiones se mantienen separadas y con referencias explícitas.

## Defaults de Condor

CondorMarketDefaults define el arranque actual:

- mode: single_country;
- primary_country: CO;
- target_countries: [CO];
- launch_countries: [CO];
- default_currency: COP;
- default_locale: es-CO.

Esos valores viven fuera del núcleo genérico MarketScope y pueden sustituirse por configuración. Cambiarlos no cambia la semántica de Market Scope.

## Market Scope

Modos soportados:

- single_country: exactamente un target principal;
- multi_country: dos o más targets explícitos;
- global: dirección global, pero sin países autorizados implícitamente.

Un país solo está autorizado para lanzamiento si aparece explícitamente en launch_countries y también pertenece a target_countries.

GLOBAL no significa todos los países live.

## Market

Cada Market conserva identidad separada por tenant, venture, market y country.

Estados canónicos:

researching -> validating -> preparing -> launch_ready -> live

paused puede interrumpir el flujo según la transición permitida.

Market también conserva, como dimensiones independientes:

- locales;
- currencies;
- legal_entity_ref;
- lex_assessment_ref;
- infrastructure_ref;
- timezone;
- source_ref;
- observed_at y freshness.

No existe migración en este slice. El modelo es un contrato de dominio puro y compatible con la persistencia multi-tenant actual.

## Market Readiness

MarketReadiness evalúa gates concretos:

- product;
- lex;
- privacy;
- localization;
- currency_pricing;
- payments_billing;
- support_knowledge;
- infrastructure;
- security;
- analytics;
- capital.

Cada gate declara status, evidence_refs y freshness.

No existe score único. Un gate UNKNOWN, GAP, stale o sin evidencia bloquea lanzamiento. NOT_APPLICABLE solo pasa con evidencia fresh. LEX debe estar SATISFIED de forma explícita.

Market no puede transicionar a launch_ready o live sin una autorización de readiness que coincida con el mismo market_context, evidencia fresh y LEX satisfecho.

## Factory

Cada blocker puede proyectarse a un WorkItem compatible con Factory Queue v1.

La proyección:

- usa origin_mode automatic;
- usa origin_system product;
- conserva venture/project/repository;
- mapea a work_type existente;
- incluye evidence_refs;
- deriva idempotency por tenant + market + gate;
- no añade provider, model, executor, scheduler, budget ni approval.

Por tanto Condor no crea una segunda cola ni concede autoridad.

## Legal y privacidad

LEX sigue siendo autoridad transversal para legal/compliance. Condor solo conserva lex_assessment_ref y consume el gate.

El modelo no almacena textos legales, documentos sensibles ni PII. Las referencias son identificadores acotados.

Añadir un Country/Jurisdiction Pack futuro no modifica MarketScope ni la semántica de Market; cambia la evidencia/gate LEX correspondiente.

## Decisiones y trade-offs

Se descartó añadir columnas a Tenant porque todavía no existe un caso multi-país persistido y una migración temprana ampliaría blast radius.

Se descartó un flag global launch_ready porque ocultaría gaps críticos.

Se descartó hardcodear Colombia dentro de MarketScope; CO/COP/es-CO pertenecen a CondorMarketDefaults.

El primer caso real multi-país decidirá la persistencia y adapters adicionales. Hasta entonces el contrato puro deja la frontera preparada sin construir un ERP fiscal global.
