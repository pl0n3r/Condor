# Smoke autenticado de producción — preparación #613

## Autoridad y límites
Autorización acotada del propietario en [Condor #613](https://github.com/pl0n3r/Condor/issues/613#issuecomment-6095039948) para preparar el smoke, no para go-live. Fase construcción y bloqueos #389, #616 y #617 intactos. No usar cuentas ni datos de clientes reales.

## Identidad sintética
El workflow authenticated-production-smoke.yml consume únicamente dos GitHub Actions Secrets del repositorio: CONDOR_SMOKE_EMAIL y CONDOR_SMOKE_PASSWORD. Solo debe corresponder a un usuario sintético de tenant con acceso mínimo a /admin y su propia sede, sin rol de propietario de plataforma. Los agentes no crean cuentas ni copian credenciales al chat.

- Ausencia de uno o ambos secrets: el job identity indica "SKIPPED: identidad sintética no configurada" y el job Smoke autenticado read-only (exact-main) aparece skipped. Nunca equivale a validación productiva.
- Presencia de ambos: ejecución diaria o mediante workflow_dispatch sobre main, checkout con github.sha exacto, regresiones offline y prueba autenticada. No activar en otras ramas.
- Credenciales incorrectas, falta de permisos, dependencia caída, versión/sha distinto de main, fuga de aislamiento o schema atrasado: resultado no-success/UNKNOWN, sin considerar GREEN.

## Circuito permitido
Origen fijo https://www.condorapp.com.co, HTTPS verificado, sin seguir redirects. Solicitudes únicamente GET /health, GET /admin/login, POST /admin/login con CSRF Symfony (única escritura HTTP para crear sesión), GET /admin, GET /api/v1/context, GET /adminpl0n3r (debe devolver 403) y GET /api/v1/context?branch=00000000000000000000000000 (debe devolver 403). El éxito exige usuario tenant sintético: /admin 200 con root del panel y contexto de sede consistente. No se crean usuarios, tenants, pedidos ni archivos; no se ejecuta logout POST.

No se persisten respuestas, cookies, identificadores tenant, tokens, email ni contraseña. Solo resumen allowlisted con state, observed_at UTC, versión, SHA y flags authenticated, tenant_safe, read_only. Los logs de red/excepciones y cuerpos HTTP quedan descartados. Los límites de tamaño, timeout y origen fallan cerrado.

## Evidencia y operación
Fuente: [Actions Condor](https://github.com/pl0n3r/Condor/actions/workflows/authenticated-production-smoke.yml). Exigir corrida terminal exacta en main, SHA coincidente con /health.release_sha, schema_up_to_date=true y versión semántica, timestamp fresco, authenticated=true, tenant_safe=true, read_only=true. No inferir VALIDADO EN PRODUCCIÓN desde la suite offline ni desde el guard identity exitoso. El checklist vivo permanece en #389.

Reversión: revertir el PR del workflow/probe; no modifica runtime, datos ni credenciales. Para corregir fallos, inspeccionar códigos allowlisted y salud del entorno sin exportar secretos. El próximo paso que implique entrar a live requiere autorización expresa del dueño.
