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
| account_identity | contact | email, display_name, roles, active, created_at | account_access | review_required | review_required | ninguno_declarado | review_required |
| account_invitation | authentication | user_id, tenant_id, token_hash, created_by_user_id, expires_at, consumed_at, revoked_at | account_invitation | review_required | review_required | ninguno_declarado | invitation_lifecycle |
| account_password | authentication | password_hash | account_security | review_required | review_required | ninguno_declarado | credential_lifecycle |
| audit_events | usage | actor_user_id, action, entity_type, entity_id, context, created_at | security_audit | review_required | review_required | ninguno_declarado | review_required |
| customer_contact | contact | name, email, phone, notes, commercial_category_id | customer_management | review_required | review_required | ninguno_declarado | review_required |
| diagnostic_shares | authentication | token_hash, expires_at, revoked_at, created_at | support_diagnostics | review_required | review_required | ninguno_declarado | diagnostic_share_lifecycle |
| error_incidents | usage | request_id, status, method, route_name, exception_class, message, fingerprint, version, release_sha, trace, occurred_at | error_diagnostics | review_required | review_required | ninguno_declarado | review_required |
| notification_delivery | usage | user_id, event_key, channel_key, payload, status, attempt_count, last_error, available_at, delivered_at | notification_delivery | review_required | review_required | ninguno_declarado | review_required |
| notification_preferences | usage | user_id, event_key, channel_key, enabled | notification_preferences | review_required | review_required | ninguno_declarado | account_lifecycle |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.
