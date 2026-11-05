# Condor #1550 — contraste de reglas de AGENTES.md (26 filas)

**Corte:** reglas de la capa local de Condor, sin tocar AGENTES.md ni docs/matriz-cobertura.md. Las 26 filas conservan el estado global NO_VERIFICABLE; una prueba offline, una referencia de archivo o un check verde no acreditan por sí solos operación real.

Clases de evidencia: CONTRATO_LOCAL prueba función versionada con entradas sintéticas; DOCUMENTAL registra política, no resultado; NAVEGADOR_PENDIENTE/HTTP_INTEGRAL_PENDIENTE/INTEGRACION_PENDIENTE indican superficie sin test transversal; OPERACION_PENDIENTE/EXTERNO_PENDIENTE requieren run, identidad y recibos auténticos cuando corresponda.

## Filas auditadas

| ID | Estado original | Fuente versionada | Prueba exacta | Clase | Brecha que permanece |
| --- | --- | --- | --- | --- | --- |
| AGENTES-02-06 | NO_VERIFICABLE | AGENTES.md | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | Se exige HEALTH degradado comprobado y despacho de recuperación real; no se infiere de la norma escrita. |
| AGENTES-03-04 | NO_VERIFICABLE | AGENTES.md | tests/test_verificacion_verify_agent_rules.py | NAVEGADOR_PENDIENTE | No hay E2E transversal de vistas, formatos y respuestas HTTP con locale es-CO. |
| AGENTES-03-05 | NO_VERIFICABLE | AGENTES.md | tests/test_verificacion_verify_agent_rules.py | INTEGRACION_PENDIENTE | La tabla contractual no verifica COP por defecto en todos los flujos monetarios. |
| AGENTES-03-06 | NO_VERIFICABLE | composer.json | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | composer fija dependencias; falta evidencia del runtime efectivamente desplegado. |
| AGENTES-04-01 | NO_VERIFICABLE | src/Application/Identity/BranchAuthorization.php | tests/test_verificacion_verify_agent_rules.py | HTTP_INTEGRAL_PENDIENTE | El control servidor de sede es un slice, no cubre todos los endpoints y permisos E2E. |
| AGENTES-04-03 | NO_VERIFICABLE | src/Domain/Organization/Entity/Branch.php | tests/test_verificacion_verify_agent_rules.py | INTEGRACION_PENDIENTE | Mapeo Doctrine de sede no demuestra todas las relaciones de negocio ni su integración DB. |
| AGENTES-04-06 | NO_VERIFICABLE | scripts/live-schema-policy.php | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Contrato aditivo local; sin prueba de despliegue parcial ni migración productiva autorizada. |
| AGENTES-05-01 | NO_VERIFICABLE | .github/workflows/politica.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | El reusable remoto requiere su ejecución auténtica y una revisión definitiva. |
| AGENTES-05-02 | NO_VERIFICABLE | scripts/ci_change_classifier.py | tests/test_verificacion_verify_agent_rules.py | CONTRATO_LOCAL | Se contrastan cambios sintéticos; no certifica todas las familias de archivos del CI. |
| AGENTES-05-03 | NO_VERIFICABLE | .github/workflows/ci.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | Declaración de job no es recibo de ejecución exact-HEAD. |
| AGENTES-05-04 | NO_VERIFICABLE | .github/workflows/ci.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | La presencia del job no acredita todos los contratos y DB de cada PR. |
| AGENTES-05-05 | NO_VERIFICABLE | .github/workflows/ci.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | La ruta declarada no equivale a un build terminal y navegador probado. |
| AGENTES-05-06 | NO_VERIFICABLE | .github/workflows/ci.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | Los controles de auditoría requieren resultados terminales auténticos. |
| AGENTES-06-01 | NO_VERIFICABLE | scripts/release_identity_guard.py | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Validación de identidad de candidato no prueba deploy real con versión. |
| AGENTES-06-02 | NO_VERIFICABLE | config/version.php | tests/test_verificacion_verify_agent_rules.py | DOCUMENTAL | Patch normal es política; no equivale a autorizar ni ejecutar nuevo release. |
| AGENTES-06-03 | NO_VERIFICABLE | scripts/release_identity_guard.py | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Guard local no observa dos despliegues reales ni historial externo completo. |
| AGENTES-06-06 | NO_VERIFICABLE | scripts/release_identity_guard.py | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Una PR real exige identidad exacta y gates; regex local no demuestra entrega. |
| AGENTES-07-01 | NO_VERIFICABLE | scripts/live-schema-policy.php | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Clasificación fail-closed no ejecuta ni certifica migraciones reales. |
| AGENTES-07-02 | NO_VERIFICABLE | scripts/live-schema-policy.php | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | El gate sintético no es un dry-run real en Hostinger. |
| AGENTES-07-03 | NO_VERIFICABLE | scripts/live-schema-policy.php | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | La bandera de fixture no sustituye un recibo verificado de backup. |
| AGENTES-07-04 | NO_VERIFICABLE | scripts/post-deploy.sh | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | La condición de shell existe; falta demostrar el opt-out en host con identidad. |
| AGENTES-07-05 | NO_VERIFICABLE | scripts/post-deploy.sh | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Orden y resultado de convergencia productiva no acreditados por texto. |
| AGENTES-07-06 | NO_VERIFICABLE | scripts/live-schema-policy.php | tests/test_verificacion_verify_agent_rules.py | OPERACION_PENDIENTE | Negativos locales no acreditan abort de pipeline productivo real. |
| AGENTES-08-03 | NO_VERIFICABLE | AGENTES.md | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | Reserva V3 y colisiones reales dependen del coordinador GitHub autenticado. |
| AGENTES-08-04 | NO_VERIFICABLE | AGENTES.md | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | El umbral escrito no incluye medición real del cupo de API ni despacho. |
| AGENTES-08-05 | NO_VERIFICABLE | .github/workflows/ci-throughput-telemetry.yml | tests/test_verificacion_verify_agent_rules.py | EXTERNO_PENDIENTE | Cron declarado no prueba una sola muestra real, frecuencia ni artifacts. |

## Aserciones ejecutables y evidencias ausentes

Las pruebas contrastan exactamente cada texto en su sección original (con rechazo de regla ausente o duplicada), existencia y token de la fuente, igualdad de las 26 filas del informe, clasificación real de cambios read-only/backend/migration/frontend y casos negativos de versión/identidad release. Se ejecuta `scripts/live-schema-policy.php` con PHP CLI y fixtures sintéticos: aditivo con recibos completos solo queda `eligible_after_owner_gate`, nunca autoejecutable; destructivo, desconocido, sin backup, sin dry-run, sin allowlist, sin schema o con campo extra son rechazados.

Los controles estáticos también exigen dependencia PHP 8.5/Symfony 7.4, denegación por tenant en servidor, relación Doctrine real de sede/tenant, declaración de las puertas CI, opt-out y dry-run de postdeploy y cron horario. No se invoca GitHub vivo, Hostinger, datos reales, shell de deploy ni migraciones. La validación de políticas no equivale a producción GREEN.

Prueba local: `python3 tests/test_verificacion_verify_agent_rules.py -v`. Esta suite **queda integrada explícitamente en el job** obligatorio de gobierno de CI, que antes solo descubría `test_ci_*.py` y suites designadas; por tanto `CI Factory v1 / Validar` en verde demuestra las puertas canónicas de ese HEAD, la ejecución de esta suite en el job obligatorio de gobierno, sin modificar permisos ni lógica de la protección. La reserva V3 ampliada reclama de forma explícita la ruta del workflow. Si PHP CLI no está presente, el subcaso live-schema-policy se marca skip, no éxito falso.

## Aceptación, seguridad y reversión

AC-01: inventario cerrado de 26 IDs, fuente, testigo y brecha explícita; ninguno promueve la matriz. AC-02: mutaciones negativas, clasificador real, guard SemVer y política PHP fail-closed. AC-03: exige `CI Factory v1 / Validar` terminal `success` y HEAD exacto antes de fusionar, además de controles de seguridad y reserva V3 auténtica.

No equivale a producción GREEN, ni da autorización para deploy, CI bypass, cambio de versión, acceso a secrets, manejo de PII, migraciones reales o solicitudes externas. Para evidencia operativa se requieren observación/recepción autenticadas y, cuando aplique, autorización del dueño. Reversión: revert íntegro del squash, incluido `.github/workflows/ci.yml` (step `Verificar reglas AGENTES.md (26 filas)`). Revertir solo la prueba y el documento dejaría el step sin archivo y fallaría el job obligatorio de gobierno; no se altera el runtime productivo.
