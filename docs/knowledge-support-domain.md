# Knowledge & Support — dominio canónico

Issue #310 define la fuente de verdad de conocimiento antes de exponer FAQ, Help Center, retrieval o IA. Este slice es **dominio PHP puro**: no persiste datos, no abre endpoints y no ejecuta búsqueda.

## Contrato

`KnowledgeArticle` representa una revisión versionada que puede consumir cualquier superficie sin duplicar contenido. El payload es cerrado: campos faltantes o adicionales fallan cerrado. Listas como tags, módulos y referencias se normalizan, deduplican y ordenan para producir un `snapshot()` y `versionFingerprint()` deterministas.

Lifecycle permitido: `draft → review → approved → published`, con retornos controlados `review → draft` y `approved → review`; contenido publicado solo puede pasar a `superseded` o `archived`, y `superseded` solo a `archived`. Publicar exige estado `approved` y freshness vigente.

## Visibilidad, audiencia y scope

Los vocabularios de `visibility` y `audience` son cerrados: `public|customer|staff`. El `scope` es explícitamente `global` o `tenant:<id>`. Nunca se infiere tenant por contexto. Un artículo tenant-scoped no puede declararse público ni tener audiencia pública; contenido `staff` tampoco puede exponerse con visibilidad `customer`.

## Freshness

`reviewed_at` y `stale_after` se definen juntos y `stale_after` debe ser posterior. Si ambos faltan, la freshness es desconocida y el artículo se considera stale. Un artículo stale no puede transicionar a `published`.

## Referencias comerciales

Knowledge no copia precios, límites ni promesas de producto. `product_version_refs` y `capability_refs` son identificadores opacos hacia las fuentes canónicas del dominio comercial. Payloads anidados o valores comerciales embebidos no forman parte del contrato.

## Trade-offs

Se usa una entidad inmutable por transición para preservar determinismo y reversibilidad sin introducir todavía repositorios, ORM ni migraciones. La persistencia, retrieval, autorización HTTP y observabilidad se añadirán en slices posteriores sobre este contrato estable.
