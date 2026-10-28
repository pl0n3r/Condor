# Paquete consolidado para revisión jurídica — Condor

Estado: documented_not_legally_approved. Documentos fuente íntegros a continuación.

---

## Documento fuente: docs/privacidad/aviso-privacidad.md

# Aviso de privacidad y autorización

> Estado: borrador técnico generado; **revisión jurídica requerida**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/Condor`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_identity | contact | email, display_name, roles, active, created_at, last_access_at | account_access | review_required | review_required | ninguno_declarado | review_required |
| account_invitation | authentication | user_id, tenant_id, token_hash, created_by_user_id, expires_at, consumed_at, revoked_at | account_invitation | review_required | review_required | ninguno_declarado | invitation_lifecycle |
| account_password | authentication | password_hash | account_security | review_required | review_required | ninguno_declarado | credential_lifecycle |
| audit_events | usage | actor_user_id, action, entity_type, entity_id, context, created_at | security_audit | review_required | review_required | ninguno_declarado | review_required |
| condor_password_reset | authentication | user_id, token_hash, expires_at, consumed_at, revoked_at | account_recovery | review_required | review_required | ninguno_declarado | password_reset_lifecycle |
| controlbot_staff_operations | usage | action, actor_key_id, target_staff_user_id, result, request_id, request_nonce_hash, request_ip, created_at, context | staff_administration_audit | review_required | review_required | ninguno_declarado | review_required |
| customer_contact | contact | name, email, phone, document_number, notes, commercial_category_id | customer_management | review_required | review_required | ninguno_declarado | review_required |
| diagnostic_shares | authentication | token_hash, expires_at, revoked_at, created_at | support_diagnostics | review_required | review_required | ninguno_declarado | diagnostic_share_lifecycle |
| error_incidents | usage | request_id, status, method, route_name, exception_class, message, fingerprint, version, release_sha, trace, occurred_at | error_diagnostics | review_required | review_required | sentry | review_required |
| human_resources_synthetic_records | usage | tenant_ref, company_ref, employee_ref, category, state, version, previous_id, previous_state, audit_action, actor_ref, occurred_at, fingerprint | review_required | review_required | review_required | ninguno_declarado | review_required |
| notification_delivery | usage | user_id, event_key, channel_key, payload, status, attempt_count, last_error, available_at, delivered_at | notification_delivery | review_required | review_required | ninguno_declarado | review_required |
| notification_preferences | usage | user_id, event_key, channel_key, enabled | notification_preferences | review_required | review_required | ninguno_declarado | account_lifecycle |
| payroll_synthetic_records | usage | tenant_ref, legal_entity_ref, record_ref, employee_ref, period_ref, actor_ref, amount_hundredths, state, created_at, fingerprint | review_required | review_required | review_required | ninguno_declarado | review_required |
| self_service_trial_application | contact | email, trial_quote_id, trial_consent_recorded_at | self_service_trial_application | review_required | review_required | ninguno_declarado | review_required |
| social_security_synthetic_records | usage | tenant_ref, company_ref, social_security_ref, category, state, version, previous_id, previous_state, audit_action, actor_ref, occurred_at | review_required | review_required | review_required | ninguno_declarado | review_required |
| workflow_definitions | usage | tenant_id, workflow_id, trigger_name, conditions_json, actions_json, created_by_user_id, updated_by_user_id, created_at, updated_at | workflow_definition_management | review_required | review_required | ninguno_declarado | review_required |
| workflow_executions | usage | tenant_id, workflow_id, definition_version, actor_user_id, result, created_at | workflow_execution_audit | review_required | review_required | ninguno_declarado | review_required |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.

---

## Documento fuente: docs/privacidad/canal-derechos.md

# Canal para ejercer derechos sobre datos personales

> Estado: borrador técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/Condor`

## Responsable y canal

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Procedimiento pendiente

- Requisitos de la solicitud: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Flujo interno de atención: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Plazos aplicables: [COMPLETAR POR EL DUEÑO] — deben ser definidos con revisión jurídica; este kit no inventa un plazo legal.

El canal debe contemplar solicitudes de acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación del consentimiento cuando corresponda. La identidad, legitimación y representación deben verificarse antes de tramitar solicitudes; los procedimientos y plazos siguen sujetos a revisión jurídica. Este documento describe una frontera técnica y no constituye aprobación jurídica.

---

## Documento fuente: docs/privacidad/politica-tratamiento.md

# Política de tratamiento de datos personales

> Estado: documento técnico generado; **no constituye aprobación jurídica**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/Condor`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| account_identity | contact | email, display_name, roles, active, created_at, last_access_at | account_access | review_required | review_required | ninguno_declarado | review_required |
| account_invitation | authentication | user_id, tenant_id, token_hash, created_by_user_id, expires_at, consumed_at, revoked_at | account_invitation | review_required | review_required | ninguno_declarado | invitation_lifecycle |
| account_password | authentication | password_hash | account_security | review_required | review_required | ninguno_declarado | credential_lifecycle |
| audit_events | usage | actor_user_id, action, entity_type, entity_id, context, created_at | security_audit | review_required | review_required | ninguno_declarado | review_required |
| condor_password_reset | authentication | user_id, token_hash, expires_at, consumed_at, revoked_at | account_recovery | review_required | review_required | ninguno_declarado | password_reset_lifecycle |
| controlbot_staff_operations | usage | action, actor_key_id, target_staff_user_id, result, request_id, request_nonce_hash, request_ip, created_at, context | staff_administration_audit | review_required | review_required | ninguno_declarado | review_required |
| customer_contact | contact | name, email, phone, document_number, notes, commercial_category_id | customer_management | review_required | review_required | ninguno_declarado | review_required |
| diagnostic_shares | authentication | token_hash, expires_at, revoked_at, created_at | support_diagnostics | review_required | review_required | ninguno_declarado | diagnostic_share_lifecycle |
| error_incidents | usage | request_id, status, method, route_name, exception_class, message, fingerprint, version, release_sha, trace, occurred_at | error_diagnostics | review_required | review_required | sentry | review_required |
| human_resources_synthetic_records | usage | tenant_ref, company_ref, employee_ref, category, state, version, previous_id, previous_state, audit_action, actor_ref, occurred_at, fingerprint | review_required | review_required | review_required | ninguno_declarado | review_required |
| notification_delivery | usage | user_id, event_key, channel_key, payload, status, attempt_count, last_error, available_at, delivered_at | notification_delivery | review_required | review_required | ninguno_declarado | review_required |
| notification_preferences | usage | user_id, event_key, channel_key, enabled | notification_preferences | review_required | review_required | ninguno_declarado | account_lifecycle |
| payroll_synthetic_records | usage | tenant_ref, legal_entity_ref, record_ref, employee_ref, period_ref, actor_ref, amount_hundredths, state, created_at, fingerprint | review_required | review_required | review_required | ninguno_declarado | review_required |
| self_service_trial_application | contact | email, trial_quote_id, trial_consent_recorded_at | self_service_trial_application | review_required | review_required | ninguno_declarado | review_required |
| social_security_synthetic_records | usage | tenant_ref, company_ref, social_security_ref, category, state, version, previous_id, previous_state, audit_action, actor_ref, occurred_at | review_required | review_required | review_required | ninguno_declarado | review_required |
| workflow_definitions | usage | tenant_id, workflow_id, trigger_name, conditions_json, actions_json, created_by_user_id, updated_by_user_id, created_at, updated_at | workflow_definition_management | review_required | review_required | ninguno_declarado | review_required |
| workflow_executions | usage | tenant_id, workflow_id, definition_version, actor_user_id, result, created_at | workflow_execution_audit | review_required | review_required | ninguno_declarado | review_required |

## Derechos y revisión

Las solicitudes de acceso, rectificación/corrección, actualización, portabilidad, supresión/eliminación o revocación del consentimiento, cuando corresponda, se canalizan mediante el canal de derechos indicado arriba, previa verificación de identidad y legitimación. La portabilidad no implica que exista un servicio automatizado de exportación. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.

---

## Documento fuente: docs/privacidad/registro-tratamientos.md

# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/Condor`

## account_identity

- Categoría: `contact`
- Campos de software: `email`, `display_name`, `roles`, `active`, `created_at`, `last_access_at`
- Finalidad: `account_access`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## account_invitation

- Categoría: `authentication`
- Campos de software: `user_id`, `tenant_id`, `token_hash`, `created_by_user_id`, `expires_at`, `consumed_at`, `revoked_at`
- Finalidad: `account_invitation`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `invitation_lifecycle`

## account_password

- Categoría: `authentication`
- Campos de software: `password_hash`
- Finalidad: `account_security`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `credential_lifecycle`

## audit_events

- Categoría: `usage`
- Campos de software: `actor_user_id`, `action`, `entity_type`, `entity_id`, `context`, `created_at`
- Finalidad: `security_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## condor_password_reset

- Categoría: `authentication`
- Campos de software: `user_id`, `token_hash`, `expires_at`, `consumed_at`, `revoked_at`
- Finalidad: `account_recovery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `password_reset_lifecycle`

## controlbot_staff_operations

- Categoría: `usage`
- Campos de software: `action`, `actor_key_id`, `target_staff_user_id`, `result`, `request_id`, `request_nonce_hash`, `request_ip`, `created_at`, `context`
- Finalidad: `staff_administration_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## customer_contact

- Categoría: `contact`
- Campos de software: `name`, `email`, `phone`, `document_number`, `notes`, `commercial_category_id`
- Finalidad: `customer_management`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## diagnostic_shares

- Categoría: `authentication`
- Campos de software: `token_hash`, `expires_at`, `revoked_at`, `created_at`
- Finalidad: `support_diagnostics`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `diagnostic_share_lifecycle`

## error_incidents

- Categoría: `usage`
- Campos de software: `request_id`, `status`, `method`, `route_name`, `exception_class`, `message`, `fingerprint`, `version`, `release_sha`, `trace`, `occurred_at`
- Finalidad: `error_diagnostics`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `sentry`
- Retención: `review_required`

## human_resources_synthetic_records

- Categoría: `usage`
- Campos de software: `tenant_ref`, `company_ref`, `employee_ref`, `category`, `state`, `version`, `previous_id`, `previous_state`, `audit_action`, `actor_ref`, `occurred_at`, `fingerprint`
- Finalidad: `review_required`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## notification_delivery

- Categoría: `usage`
- Campos de software: `user_id`, `event_key`, `channel_key`, `payload`, `status`, `attempt_count`, `last_error`, `available_at`, `delivered_at`
- Finalidad: `notification_delivery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## notification_preferences

- Categoría: `usage`
- Campos de software: `user_id`, `event_key`, `channel_key`, `enabled`
- Finalidad: `notification_preferences`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `account_lifecycle`

## payroll_synthetic_records

- Categoría: `usage`
- Campos de software: `tenant_ref`, `legal_entity_ref`, `record_ref`, `employee_ref`, `period_ref`, `actor_ref`, `amount_hundredths`, `state`, `created_at`, `fingerprint`
- Finalidad: `review_required`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## self_service_trial_application

- Categoría: `contact`
- Campos de software: `email`, `trial_quote_id`, `trial_consent_recorded_at`
- Finalidad: `self_service_trial_application`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## social_security_synthetic_records

- Categoría: `usage`
- Campos de software: `tenant_ref`, `company_ref`, `social_security_ref`, `category`, `state`, `version`, `previous_id`, `previous_state`, `audit_action`, `actor_ref`, `occurred_at`
- Finalidad: `review_required`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## workflow_definitions

- Categoría: `usage`
- Campos de software: `tenant_id`, `workflow_id`, `trigger_name`, `conditions_json`, `actions_json`, `created_by_user_id`, `updated_by_user_id`, `created_at`, `updated_at`
- Finalidad: `workflow_definition_management`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## workflow_executions

- Categoría: `usage`
- Campos de software: `tenant_id`, `workflow_id`, `definition_version`, `actor_user_id`, `result`, `created_at`
- Finalidad: `workflow_execution_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

---

## Documento fuente: docs/privacidad/retencion.md

# Tabla de retención

> Estado: calendario técnico documentado; **revisión jurídica requerida**.

Producto: `pl0n3r/Condor`

| Tratamiento | Categoría | Retención declarada | Máximo común |
| --- | --- | --- | --- |
| account_identity | contact | review_required | review_required |
| account_invitation | authentication | invitation_lifecycle | review_required |
| account_password | authentication | credential_lifecycle | review_required |
| audit_events | usage | review_required | review_required |
| condor_password_reset | authentication | password_reset_lifecycle | review_required |
| controlbot_staff_operations | usage | review_required | review_required |
| customer_contact | contact | review_required | review_required |
| diagnostic_shares | authentication | diagnostic_share_lifecycle | review_required |
| error_incidents | usage | review_required | review_required |
| human_resources_synthetic_records | usage | review_required | review_required |
| notification_delivery | usage | review_required | review_required |
| notification_preferences | usage | account_lifecycle | review_required |
| payroll_synthetic_records | usage | review_required | review_required |
| self_service_trial_application | contact | review_required | review_required |
| social_security_synthetic_records | usage | review_required | review_required |
| workflow_definitions | usage | review_required | review_required |
| workflow_executions | usage | review_required | review_required |

---

## Documento fuente: docs/privacidad/terminos-condiciones.md

# Términos y condiciones

> Estado: borrador técnico generado; **revisión jurídica requerida antes de publicación**.

Producto: `pl0n3r/Condor`

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Condiciones pendientes de definición

- Condiciones comerciales: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Niveles de servicio (SLA), si aplican: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Garantías y limitaciones aplicables: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Jurisdicción y mecanismo de solución de controversias: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.

Este documento no inventa ni presume condiciones del producto. Los hechos comerciales y jurídicos anteriores deben completarse y aprobarse antes de su uso público.

---

## Documento fuente: docs/arquitectura-entidades-legales.md

# Titularidad jurídica y operaciones interempresa en Condor

**Decisión canónica:** D-052 en [`ESPECIFICACIONES.md`](../ESPECIFICACIONES.md). **Trabajo relacionado:** [Issue #177](https://github.com/pl0n3r/Condor/issues/177).  
**Estado:** contrato de arquitectura; PR #178 permanece draft hasta que #177 cumpla sus criterios ejecutables. Este documento no declara funciones ni migraciones desplegadas.  
**Fuente de implementación:** `main` y los tests del SHA fusionado.  
**Dependencias:** V 0.1.21 y requisitos del primer cliente ya integrados en `main`; [Inventario #171 / PR #172](https://github.com/pl0n3r/Condor/pull/172) aplica la primera frontera ejecutable. Esa aplicación solo se considera integrada cuando #172 haya sido fusionado y validado sobre `main`; mientras permanezca en draft/candidato, este documento describe el contrato y su evidencia esperada, no una capacidad desplegada. Este documento no acredita por sí solo deploy ni migración productiva.

## 1. Modelo de pertenencia

`Tenant` es la cuenta/grupo operativo contratado en Condor, no una razón social ni un almacén. `LegalEntity` es el titular jurídico/fiscal que opera dentro del tenant. Un tenant puede tener varias entidades legales; onboarding conserva una entidad primaria y una sede inicial. `Branch` es una sede operativa y `InventorySource` es una ubicación lógica o física de stock. Ni sede ni fuente sustituyen al titular jurídico.

Los dominios con propiedad jurídica deben registrar un `legal_entity_id` **efectivo**, tenant-owned, en su operación y en cualquier proyección que se consulte o exporte: inventario, pedidos/ventas, abastecimiento, costos y fabricación. El catálogo `Product`/`ProductVariant` puede continuar compartido a nivel tenant; compartir la ficha **no comparte automáticamente** saldo, titularidad, costo, precio efectivo, permiso ni historial.

### Lo que NO significa

- Dos razones sociales en un grupo no obligan a crear dos tenants, ni habilitan automáticamente acceso transversal.
- Una cuenta multi-entidad no equivale a una sola contabilidad o existencia consolidada.
- Seleccionar una razón social en UI no es una autorización.
- Una transferencia física de stock no registra por sí sola una compra, venta, factura, prestación de servicio ni cambio de titularidad.
- Este contrato no autoriza migración, backfill, deploy ni pruebas destructivas de producción.

## 2. Alcance y autorización

| Capa | Regla comprobable |
| --- | --- |
| Identidad | La sesión autentica usuario y resuelve tenant con membresía activa. |
| Contexto | La razón social efectiva se selecciona explícitamente entre las autorizadas; no se obtiene del slug, nombre, URL no verificada o fuente elegida por el cliente. |
| Sede | Un delegado conserva el alcance de sus roles por sede; la asociación a una entidad legal no le concede acceso a todas sus sedes. |
| Fuente lógica | Al no tener sede, necesita permiso explícito de gestión/consulta en la entidad legal; no hereda permisos de una sede ajena. |
| Lectura | API, dashboards, historial y exportaciones filtran por tenant, entidad legal y sedes/fuentes permitidas. No unir resultados de otra entidad solo porque pertenezca al tenant. |
| Escritura | Resolver y autorizar tenant, entidad legal, sede/fuente y permiso server-side **antes** de cambiar saldos; petición cross-tenant o cross-entity falla sin efectos. |
| Propietario | Si el producto permite gestión grupal, el owner puede operar entidades del mismo tenant mediante contexto explícito; no por mezclar ids de entidades en una sola transacción. |

La capa HTTP determina 403/404/409 según identidad, existencia y compatibilidad legacy, evitando revelar registros ajenos. La base de datos es segunda barrera: FKs y unicidades compuestas incorporan tenant y entidad legal donde corresponde.

## 3. Inventory: invariantes del Slice 4

1. Cada `InventorySource` identifica exactamente un `Tenant` y una `LegalEntity` del mismo tenant.
2. Una fuente de tipo sede referencia una `Branch` cuya `legalEntity` coincide. Fuente lógica declara su entidad aunque `branch_id` sea NULL.
3. `InventoryBalance` representa cantidad por `tenant + legal_entity + source + variant`. La variante puede ser tenant-owned sin pertenecer exclusivamente a una sociedad.
4. `InventoryMovement` es auditable y preserva titularidad, origen/destino cuando proceda, actor y estado resultante. No puede referenciar fuente, saldo o transferencia de otra entidad legal.
5. `InventoryTransfer` normal requiere fuente origen y destino **de la misma entidad legal**; `tenant + entidad + source + variant` constituyen la frontera de consistencia.
6. La idempotencia y el locking de escritura deben respetar esa frontera. Repetir una misma operación no crea movimientos ni auditorías comerciales duplicadas.
7. Ajustes y transferencias que fallen conservan saldos, movimientos y auditoría de la operación como una unidad coherente. `allow_backorder` no permite eludir titularidad.
8. Si `Branch::legalEntity()` es NULL en una sede anterior al cambio, la API de inventario rechaza explícitamente esa sede sin asignarla en silencio a la entidad primaria.

### Persistencia

Las referencias fuertes deben impedir asociaciones cruzadas aun si se omite un filtro en un controlador. Para inventario, usar las claves compuestas equivalentes a `(tenant_id, legal_entity_id, id)` en fuente y transferencia y FKs desde saldo/movimiento/transferencia a su entidad y fuente; preservar las FKs tenant + variant existentes. Un campo `legal_entity_id` aislado **sin FKs compuestas** no demuestra este contrato.

### Frontera sede ↔ fuente en la propia base de datos

Hay una diferencia crucial entre dos FKs correctas por separado y **una pertenencia jurídica coherente**. Una fuente con `(tenant=A, legal_entity=X, branch_id=B)` puede pasar tanto la FK `(tenant,legal_entity)` a `LegalEntity` como la FK `(tenant,branch_id)` a `Branch`, aunque la sede B pertenezca a la entidad Y del mismo tenant. El constructor y la autorización HTTP lo deben rechazar, pero no son sustitutos de la segunda barrera relacional.

En el slice de Inventario, la migración deberá ofrecer una clave candidata `Branch(tenant_id,legal_entity_id,id)` y una FK desde `InventorySource(tenant_id,legal_entity_id,branch_id)` hacia esa clave. Esta FK no impide sedes legacy con `legal_entity_id=NULL` en la tabla `Branch`; sí impide crear una fuente de sede que apunte a una sede sin titular compatible. Una fuente lógica utiliza `branch_id=NULL`, declara titular de forma explícita y no hereda acceso por relación de sede. Mantener además la validación de tipo de fuente (sede requiere branch; lógica no admite branch) en dominio y, donde se soporte sin romper compatibilidad, con una restricción de base de datos.

**Regresión MariaDB requerida:** crear dos entidades jurídicas X/Y del *mismo tenant* y su sede B en Y; intentar insertar por SQL una fuente de X apuntando a B. Debe fallar por FK sin depender de `InventorySource::__construct` ni del controlador. Repetir con una sede legacy `legal_entity_id=NULL` y fuente no nula, también denegada. Una fuente lógica válida de X con sede NULL y un movimiento dentro de X siguen permitidos. Verificar `doctrine:schema:validate` y la migración en MariaDB descartable antes de incorporar el cambio; nunca corregir sedes productivas a ciegas.

La ruta de integración exige pruebas MariaDB reales que intenten insertar o modificar asociaciones cruzadas, además de pruebas del servicio. La inspección de ORM por sí sola no es evidencia suficiente.

## 4. Despliegue compatible para sedes anteriores

`Branch.legal_entity_id` existe actualmente como nullable. No aplicar `NOT NULL`, migraciones destructivas o un backfill implícito sobre producción al preparar Inventario.

1. **Expand:** añadir campos/índices y servicios compatibles con sedes antiguas; rechazar claramente operaciones nuevas que carezcan de titular, sin falsificar pertenencia.
2. **Diagnóstico read-only:** contar sedes sin entidad por tenant y verificar la entidad primaria existente. Una razón social primaria no siempre determina el dueño correcto de una sede histórica.
3. **Asignación autorizada:** proponer mapeo de sedes a entidades jurídicas, preservar evidencia y ensayarlo en datos descartables. Si hay ambigüedad, no asignar automáticamente.
4. **Validación:** casos de una y dos entidades dentro del mismo tenant, un tenant ajeno, rollback, índices/FKs y rutas de administración.
5. **Contract posterior:** estrechar nullabilidad solo cuando la reconciliación y compatibilidad de todas las rutas estén demostradas, con despliegue y migración productivos aprobados separadamente.

El pipeline debe distinguir `IMPLEMENTADO`, `VALIDADO EN CÓDIGO`, `DESPLEGADO` y `VALIDADO EN PRODUCCIÓN`; ningún test con MariaDB descartable acredita un backfill real.

## 5. Intercompany no es transferencia interna

Cuando hay cambio de titularidad jurídica se usa **otro caso de uso**. El contrato futuro debe registrar entidad origen, entidad destino, motivo/tipo (venta entre sociedades, fabricación por encargo, compra o devolución), mercancía/cantidades, valoración y moneda, documento/referencia, impuestos cuando apliquen, eventos/actor y reversión o compensación. El movimiento físico del inventario será un efecto transaccional del contrato intercompany, no su definición.

No se implementa en #171/#172 una excepción `crossEntity=true` ni se reinterpretan dos `InventorySource` de entidades diferentes como si compartieran saldo.

## 6. Matriz mínima de aceptación

| Caso | Resultado esperado | Capa |
| --- | --- | --- |
| Dos `LegalEntity` del mismo tenant con dos sedes | saldos e historial separados aunque usen la misma variante | MariaDB + servicio + HTTP |
| Entidad de otro tenant | rechazado al crear fuente, saldo, movimiento o transferencia | dominio + FK + HTTP |
| Fuente sede con entidad distinta a la sede | rechazada antes de persistir | dominio + FK |
| Fuente lógica de otra entidad dentro del tenant | 404/403 sin saldo ni movimiento nuevo | HTTP + MariaDB |
| Delegado autorizado solo en sede A | no consulta ni muta sede B por compartir entidad legal | permisos + HTTP |
| Transferencia dentro de una entidad | dos movimientos vinculados o ninguno | servicio + MariaDB |
| Transferencia entre entidades jurídicas | rechazada como transferencia ordinaria; intercompany separado | servicio + FK + HTTP |
| Sede legacy sin entidad | 409 diagnosticable, nunca asignación silenciosa | HTTP |
| Dos ajustes simultáneos al primer saldo | una sola fila de balance y sin lost update | concurrencia MariaDB |
| Tenant de una razón social | contexto primario automático sin selector visible innecesario | UI + HTTP |
| Tenant multi-entidad | selector solo con entidades autorizadas; no eleva permisos | UI + HTTP |

**Secuencia de entrega:** cerrar Inventario #171/#172 con gates exact-head y exact-main; después aplicar esta misma invariante a ventas, costos, fabricación y reporting mediante slices reusables. La decisión durable queda registrada en D-052 de `ESPECIFICACIONES.md`. Los RF del primer cliente sirven de validación de producto, no de condicionales de código.

