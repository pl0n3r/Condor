# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/Condor`

## account_identity

- Categoría: `contact`
- Campos de software: `email`, `display_name`, `roles`, `active`, `created_at`
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

## customer_contact

- Categoría: `contact`
- Campos de software: `name`, `email`, `phone`, `notes`, `commercial_category_id`
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
