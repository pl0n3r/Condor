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
| contract_synthetic_records | usage | tenant_ref, company_ref, contract_ref, worker_ref, template_ref, actor_ref, state, version, audit_action, fingerprint, created_at | review_required | review_required | review_required | ninguno_declarado | review_required |
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
