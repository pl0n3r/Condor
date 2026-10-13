# Smoke autenticado de producción — preparación #613

## Autoridad y límites
Autorización acotada del propietario en [Condor #613](https://github.com/pl0n3r/Condor/issues/613#issuecomment-6095039948) para preparar el smoke, no para go-live. Fase construcción y bloqueos #389, #616 y #617 intactos. No usar cuentas ni datos de clientes reales.

## Identidad sintética
El workflow authenticated-production-smoke.yml consume únicamente dos GitHub Actions Secrets del repositorio: CONDOR_SMOKE_EMAIL y CONDOR_SMOKE_PASSWORD. Solo debe corresponder a un usuario sintético de tenant con acceso mínimo a /admin y su propia sede, sin rol de propietario de plataforma. Los agentes no crean cuentas ni copian credenciales al chat.

- **Puerta adicional desactivada por defecto:** ambos jobs de acceso a identidad/producto requieren `vars.CONDOR_AUTH_SMOKE_ENABLED == 'true'`. Sin habilitación explícita posterior y autorizada de esa variable, el horario diario y `workflow_dispatch` **no cargan secrets ni contactan producción**; quedan `SKIPPED`. Preparar o fusionar código nunca activa por sí mismo esta puerta. No configurar esta variable en este PR.
- Ausencia de uno o ambos secrets (cuando la puerta anterior esté habilitada): el job identity indica "SKIPPED: identidad sintética no configurada" y el job Smoke autenticado read-only (exact-main) aparece skipped. Nunca equivale a validación productiva.
- Presencia de ambos: ejecución diaria o mediante workflow_dispatch sobre main, checkout con github.sha exacto, regresiones offline y prueba autenticada. No activar en otras ramas.
- Credenciales incorrectas, falta de permisos, dependencia caída, versión/sha distinto de main, fuga de aislamiento o schema atrasado: resultado no-success/UNKNOWN, sin considerar GREEN.

## Precondiciones del fixture y límites de la evidencia
Para que `GET /api/v1/context` pueda devolver 200 sin fabricar un estado, la identidad sintética necesita **exactamente una membresía tenant activa** si no tiene tenant seleccionado y **al menos una sede autorizada**; una cuenta sin sede o con selección ambigua puede recibir 403 por diseño. La comprobación `?branch=` usa el **ID de sede**, no el slug. Un 403 ante un ID de sede inexistente acredita el veto a una sede desconocida, **no** demuestra aislamiento entre dos tenants distintos. La evidencia *cross-tenant* permanece `NOT_VERIFIED` hasta disponer de dos tenants y sedes estrictamente sintéticos, autorizados y separados; no se asigna `tenant_safe=true` como sinónimo de cobertura cross-tenant real.

El gate `release_identity_guard.py` del CI también exige que la versión candidata de `config/version.php` sea inédita y coincida con el sufijo `(V X.Y.Z)` del título del PR; la versión vigente en producción y el SHA en `/health` son identidad operativa separada y jamás se deducen del candidato. Un cambio de título o versión no dispara por sí mismo un smoke real ni autoriza publicar.

## Circuito permitido
Origen fijo https://www.condorapp.com.co, HTTPS verificado. El único redirect seguido es el 302/303 del POST de login hacia /admin en el mismo origen, validado antes de hacer la petición; todos los demás redirects se rechazan. Solicitudes únicamente GET /health, GET /admin/login, POST /admin/login con CSRF Symfony (única escritura HTTP para crear sesión), GET /admin, GET /api/v1/context, GET /adminpl0n3r (debe devolver 403) y GET /api/v1/context?branch=00000000000000000000000000 (debe devolver 403). El éxito exige usuario tenant sintético: /admin 200 con root del panel y contexto de sede consistente. No se crean usuarios, tenants, pedidos ni archivos; no se ejecuta logout POST.

No se persisten respuestas, cookies, identificadores tenant, tokens, email ni contraseña. Solo resumen allowlisted con state, observed_at UTC, versión, SHA y flags authenticated, tenant_safe=false, tenant_context_consistent, unknown_branch_denied, cross_tenant_verified=false y read_only. La ausencia de una segunda identidad sintética nunca autoriza presentar aislamiento entre tenants como validado. Los logs de red/excepciones y cuerpos HTTP quedan descartados. Los límites de tamaño, timeout y origen fallan cerrado.

## Evidencia y operación
Fuente: [Actions Condor](https://github.com/pl0n3r/Condor/actions/workflows/authenticated-production-smoke.yml). Exigir corrida terminal exacta en main, SHA y versión del checkout coincidentes con /health al inicio y al final, schema_up_to_date=true, timestamp fresco, authenticated=true, tenant_context_consistent=true, unknown_branch_denied=true y read_only=true. Este smoke verifica solo el ámbito de una identidad sintética: tenant_safe=false y cross_tenant_verified=false hasta una prueba independiente entre dos tenants sintéticos autorizados; esos flags no bastan para dar por cerradas las puertas cross-tenant de #389. No inferir VALIDADO EN PRODUCCIÓN desde la suite offline ni desde el guard identity exitoso. El checklist vivo permanece en #389.

Reversión: revertir el PR del workflow/probe; no modifica runtime, datos ni credenciales. Para corregir fallos, inspeccionar códigos allowlisted y salud del entorno sin exportar secretos. El próximo paso que implique entrar a live requiere autorización expresa del dueño.
