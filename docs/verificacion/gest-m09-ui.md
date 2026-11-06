# Condor · GEST-M09-S02/S03 · previews HTTP sintéticos en compras (#1578)

**Corte:** construcción, 6-nov-2026. Dependencia #1534 completada
(PR #1592 fusionado); no sustituye su matriz de 8 filas ni promueve los
recorridos a CUBIERTO integral antes de la integración de este PR.

## Alcance comprobable

| Fila | Endpoint autenticado, rama del usuario | Resultado y límites |
| --- | --- | --- |
| GEST-M09-S02, cotización | POST /api/v1/branches/{branchId}/purchasing/quotations/preview | Devuelve quotation_preview con status SYNTHETIC_PREVIEW, COP, total de ejemplo y flags de acción en false |
| GEST-M09-S03, solicitud | POST /api/v1/branches/{branchId}/purchasing/requests/preview | Devuelve request_preview pendiente, sin aprobación, orden, pago, notificación ni persistencia |

La UI de PurchasingManagement.tsx usa exclusivamente los fixtures
fixture:tenant/current, fixture:legal-entity/current,
fixture:site/current y fixture:item/paper. No toma proveedores,
materiales, tenant, entidad, datos personales ni precios de la operación.
Las peticiones usan same-origin, un token CSRF de la sesión y la sede
autenticada del contexto admin. El servidor vuelve a resolver tenant,
entidad, sede y permiso inventory.create antes de aceptar el cuerpo.

**Estados UI independientes para ambas rutas:** empty (sin resultado al
abrir), loading (POST en vuelo), ready (solo respuesta synthetic_only
válida y flags no operativos explícitos), error (HTTP 400/404/422,
red o respuesta incongruente) y permission-denied (HTTP 401/403 o rol
sin permiso). Un cambio de sede invalida los resultados en vuelo.

## Negativos HTTP reales

tests/test_m09_purchasing_preview_http.py crea un WebTestCase temporal
con entidades y cuentas @example.test únicamente sintéticas, inicia
sesión, obtiene CSRF de /admin y ejecuta ambas rutas HTTP del kernel:

- éxito 200 con flags can_order, can_pay, can_approve,
  can_dispatch y persisted en false;
- CSRF ausente => 403; sede de otro tenant => 404;
- usuario lector sin inventory.create y sede oculta => 403;
- referencias ajenas en cuerpo o línea, campo de PII inesperado => 422;
- JSON mal formado => 400.

Además verifica fuente TypeScript y bundle public/build/admin.js,
incluido el bloqueo de resultados viejos y de respuestas no sintéticas.

## Límite de autoridad

Los endpoints solo se habilitan en test o dev con las banderas
CONDOR_PURCHASING_SYNTHETIC_API=1 y
CONDOR_PRODUCTION_STAGE=construccion. En cualquier otro entorno
responden 404. La UI no concede compras, evaluación real de proveedores,
notificaciones, pedidos, aprobaciones, persistencia, pagos, despliegue,
tráfico productivo ni certificación de un flujo jurídico o comercial.
Conservar NO_VERIFICABLE integral si faltan permisos, integración
productiva o recibos reales. Revertir el squash revierte esta UI.
