# Paquete único para consulta con abogado: siete repositorios

**Corte:** 31 de octubre de 2026. **Estado:** documented_not_legally_approved. No constituye asesoría ni aprobación legal, autorización de datos reales, contratación o go-live. La contratación y el momento de revisión corresponden al dueño.

## Cómo utilizar este paquete

- Inventario técnico copiado desde datos.yml cuando existe, en la revisión de main indicada por el blob; los borradores jurídicos oficiales permanecen en cada repositorio enlazado.
- Base review_required significa análisis jurídico pendiente; estados técnicos de ciclo de vida no son plazos legales aprobados. Ningún proveedor declarado NO demuestra ausencia de terceros.
- La persona profesional debe responder cada pregunta, determinar jurisdicción, rol responsable/encargado, tercero, plazo, procedencia y firmar o referenciar un dictamen por versión.
- No insertar datos personales reales o identificaciones en repositorios públicos. No activar live ni cerrar puertas legales por el solo hecho de disponer de esta carpeta.
- Prueba ejecutable: tests/test_paquete_abogado.py::PaqueteAbogadoTests::test_criterio_principal.

## Datos del responsable pendientes de completar

Todos los marcadores siguientes quedan deliberadamente sin completar; no inventar razón social, identificación, domicilio ni canal de derechos. Para Factory proceden de plantillas del kit; los demás constan en datos.yml.

| Proyecto | Nombre o razón social | Identificación / NIT | Domicilio / dirección | Canal de derechos / correo |
| --- | --- | --- | --- | --- |
| Factory | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| Condor | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| ControlBot | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| GrindFlow | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| BRVTAL | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| FactoryRunner | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |
| AutoFactory | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] | [COMPLETAR POR EL DUEÑO] |

**Otras incógnitas transversales:** representación, jurisdicción, entidades responsables y encargadas, base/consentimiento por finalidad, menores y datos especiales, garantías de terceros y transferencias, plazos/medidas de eliminación, seguridad, incidentes, licencias, aprobación y autoridad de lanzamiento. Ninguna se considera satisfecha.

## Factory

- **Estado:** documented_not_legally_approved. No existe inventario datos.yml propio de Factory en main: no atribuirle tratamientos de los seis consumidores.
- **Fuentes para revisión:** [índice jurídico](https://github.com/pl0n3r/factory/blob/main/revision-juridica/00-LEEME.md), [paquete](https://github.com/pl0n3r/factory/blob/main/revision-juridica/paquete-completo.md), [cumplimiento/licencias](https://github.com/pl0n3r/factory/blob/main/revision-juridica/docs/cumplimiento-datos-licencias.md), [reglas de datos](https://github.com/pl0n3r/factory/blob/main/revision-juridica/legal/reglas-datos.yml), [plantillas legales](https://github.com/pl0n3r/factory/tree/main/revision-juridica/legal/plantillas).
- **Datos/bases/retención:** confirmar si logs de coordinación, CI y revisión contienen datos de colaboradores. No se ha verificado base jurídica ni plazo.
- **Terceros:** GitHub/Actions, SonarCloud y CodeRabbit figuran como servicios técnicos; comprobar si realmente reciben datos personales. La detección de otros servicios no prueba que estén activos.
- **Derechos:** revisar [plantilla de canal](https://github.com/pl0n3r/factory/blob/main/revision-juridica/legal/plantillas/canal-derechos.md.tpl), pendiente de completar y aprobar.

### Preguntas concretas: Factory
1. **Pregunta:** ¿Es Factory responsable autónomo o infraestructura técnica sin tratamiento personal propio?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Qué base y transparencia corresponden a logs, GitHub Issues y revisiones automáticas?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Qué retención y excepciones proceden para auditorías y trazas de colaboradores?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Qué proveedores efectivos y transferencias aplican a servicios CI y análisis de código?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Qué plantillas requieren adaptación por producto y qué prueba de aprobación humana se exige?
   **Respuesta del abogado (pendiente):** ____________________________________________

## Condor

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/Condor/blob/main/datos.yml) — blob 0da7535fa27f98db998a09298183861098d9acfe; [revision-juridica/00-LEEME.md](https://github.com/pl0n3r/Condor/blob/main/revision-juridica/00-LEEME.md); [revision-juridica/paquete-completo.md](https://github.com/pl0n3r/Condor/blob/main/revision-juridica/paquete-completo.md); [docs/privacidad/aviso-privacidad.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/aviso-privacidad.md); [docs/privacidad/canal-derechos.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/canal-derechos.md); [docs/privacidad/politica-tratamiento.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/politica-tratamiento.md); [docs/privacidad/registro-tratamientos.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/registro-tratamientos.md); [docs/privacidad/retencion.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/retencion.md); [docs/privacidad/terminos-condiciones.md](https://github.com/pl0n3r/Condor/blob/main/docs/privacidad/terminos-condiciones.md).
- **Resumen de datos:** 18 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** La fuente docs/privacidad/canal-derechos.md es borrador; confirmar contacto, legitimación y plazos.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: Condor

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| account_identity | contact / account_access | review_required | review_required | ninguno declarado — NO VERIFICADO |
| account_password | authentication / account_security | review_required | credential_lifecycle | ninguno declarado — NO VERIFICADO |
| condor_password_reset | authentication / account_recovery | review_required | password_reset_lifecycle | ninguno declarado — NO VERIFICADO |
| customer_contact | contact / customer_management | review_required | review_required | ninguno declarado — NO VERIFICADO |
| account_invitation | authentication / account_invitation | review_required | invitation_lifecycle | ninguno declarado — NO VERIFICADO |
| notification_preferences | usage / notification_preferences | review_required | account_lifecycle | ninguno declarado — NO VERIFICADO |
| notification_delivery | usage / notification_delivery | review_required | review_required | ninguno declarado — NO VERIFICADO |
| audit_events | usage / security_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| error_incidents | usage / error_diagnostics | review_required | review_required | sentry |
| diagnostic_shares | authentication / support_diagnostics | review_required | diagnostic_share_lifecycle | ninguno declarado — NO VERIFICADO |
| controlbot_staff_operations | usage / staff_administration_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| self_service_trial_application | contact / self_service_trial_application | review_required | review_required | ninguno declarado — NO VERIFICADO |
| workflow_definitions | usage / workflow_definition_management | review_required | review_required | ninguno declarado — NO VERIFICADO |
| workflow_executions | usage / workflow_execution_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| payroll_synthetic_records | usage / review_required | review_required | review_required | ninguno declarado — NO VERIFICADO |
| social_security_synthetic_records | usage / review_required | review_required | review_required | ninguno declarado — NO VERIFICADO |
| human_resources_synthetic_records | usage / review_required | review_required | review_required | ninguno declarado — NO VERIFICADO |
| contract_synthetic_records | usage / review_required | review_required | review_required | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: Condor
1. **Pregunta:** ¿Quién es responsable o encargado de datos de clientes y tenants?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Qué bases aplican a cuentas, invitaciones, notificaciones y solicitudes de prueba?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Cuándo se eliminan auditorías, incidentes Sentry y registros sintéticos de nómina?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Qué contratos y transferencias exige Sentry/Hostinger si tratan datos personales?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Cómo ejercer y verificar derechos y qué condiciones habilitarían clientes reales?
   **Respuesta del abogado (pendiente):** ____________________________________________

## ControlBot

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/ControlBot/blob/main/datos.yml) — blob 7b37f192520b2a34efcfa57306d9d0ddf49d822c; [revision-juridica/00-LEEME.md](https://github.com/pl0n3r/ControlBot/blob/main/revision-juridica/00-LEEME.md); [revision-juridica/paquete-completo.md](https://github.com/pl0n3r/ControlBot/blob/main/revision-juridica/paquete-completo.md); [docs/privacidad/aviso-privacidad.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/aviso-privacidad.md); [docs/privacidad/canal-derechos.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/canal-derechos.md); [docs/privacidad/politica-tratamiento.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/politica-tratamiento.md); [docs/privacidad/registro-tratamientos.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/registro-tratamientos.md); [docs/privacidad/retencion.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/retencion.md); [docs/privacidad/terminos-condiciones.md](https://github.com/pl0n3r/ControlBot/blob/main/docs/privacidad/terminos-condiciones.md).
- **Resumen de datos:** 8 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** La fuente docs/privacidad/canal-derechos.md es borrador; confirmar contacto, legitimación y plazos.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: ControlBot

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| account_profiles | identification / account_inventory | review_required | review_required | ninguno declarado — NO VERIFICADO |
| agent_status | usage / agent_orchestration | review_required | review_required | ninguno declarado — NO VERIFICADO |
| audit_log | usage / security_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| chat_response_opt_in | usage / agent_response_relay | review_required | review_required | ninguno declarado — NO VERIFICADO |
| dashboard_health_status | usage / dashboard_operational_status | review_required | review_required | ninguno declarado — NO VERIFICADO |
| owner_login_credentials | authentication / owner_login_security | review_required | review_required | ninguno declarado — NO VERIFICADO |
| staff_directory_contact | contact / staff_administration | review_required | request_lifetime_only | ninguno declarado — NO VERIFICADO |
| staff_directory_identity | identification / staff_administration | review_required | request_lifetime_only | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: ControlBot
1. **Pregunta:** ¿Qué base permite vincular cuentas, perfiles, pestañas y textos opt-in?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Cómo diferenciar responsable y encargado en coordinación interrepositorios?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Qué retención concreta corresponde a auditoría y personal interno?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Cómo ejercer derechos si hay referencias a conversaciones y logs?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Qué autorizaciones requiere conectar cuentas y sesiones reales?
   **Respuesta del abogado (pendiente):** ____________________________________________

## GrindFlow

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/GrindFlow/blob/main/datos.yml) — blob 00d2f385bca7e48b24ff737251234917f7c35e2e; [revision-juridica/00-LEEME.md](https://github.com/pl0n3r/GrindFlow/blob/main/revision-juridica/00-LEEME.md); [revision-juridica/paquete-completo.md](https://github.com/pl0n3r/GrindFlow/blob/main/revision-juridica/paquete-completo.md); [docs/privacidad/aviso-privacidad.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/aviso-privacidad.md); [docs/privacidad/canal-derechos.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/canal-derechos.md); [docs/privacidad/politica-tratamiento.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/politica-tratamiento.md); [docs/privacidad/registro-tratamientos.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/registro-tratamientos.md); [docs/privacidad/retencion.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/retencion.md); [docs/privacidad/terminos-condiciones.md](https://github.com/pl0n3r/GrindFlow/blob/main/docs/privacidad/terminos-condiciones.md).
- **Resumen de datos:** 16 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** La fuente docs/privacidad/canal-derechos.md es borrador; confirmar contacto, legitimación y plazos.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: GrindFlow

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| account_credentials | authentication / account_security | review_required | review_required | ninguno declarado — NO VERIFICADO |
| account_identity | contact / account_access | review_required | review_required | supabase |
| cloud_connections | authentication / cloud_media_connection | review_required | review_required | dropbox, google_drive, supabase |
| legacy_supabase_link_clicks | usage / traffic_click_analytics | review_required | review_required | supabase |
| legacy_supabase_platform_credentials | authentication / publishing_account_connection | review_required | review_required | supabase |
| legacy_supabase_upload_audit | location / upload_security_audit | review_required | review_required | supabase |
| media_vault | usage / media_management | review_required | review_required | cloudflare_r2, supabase |
| organization_membership | identification / tenant_authorization | review_required | review_required | supabase |
| traffic_dedupe | usage / traffic_deduplication | review_required | dedupe_24h | ninguno declarado — NO VERIFICADO |
| traffic_links | usage / traffic_attribution | review_required | review_required | supabase |
| traffic_metrics | usage / traffic_measurement | review_required | review_required | ninguno declarado — NO VERIFICADO |
| staff_identity | identification / staff_administration | review_required | review_required | ninguno declarado — NO VERIFICADO |
| staff_contact | contact / staff_invitation_and_recovery | review_required | review_required | ninguno declarado — NO VERIFICADO |
| staff_access_metadata | usage / staff_security_monitoring | review_required | review_required | ninguno declarado — NO VERIFICADO |
| instagram_reels_publication | usage / professional_instagram_reels_publication | review_required | review_required | meta_instagram |
| diagnostic_incidents | usage / application_error_diagnostics | review_required | review_required | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: GrindFlow
1. **Pregunta:** ¿Qué base aplica al seguimiento de clics y analítica de tráfico?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Qué DPA/transferencias requieren Supabase, Dropbox, Google Drive, R2 y Meta?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Qué plazo aplica a tokens, conexiones, medios y auditoría de subidas?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Qué aviso, revocación y oposición corresponde a medición y publicaciones?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Qué responsabilidad existe por licencias de medios y contenido subido?
   **Respuesta del abogado (pendiente):** ____________________________________________

## BRVTAL

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/brvtal/blob/main/datos.yml) — blob f8b3068e0f3948f76dad80a0a0dbc6c63b7b59e6; [revision-juridica/00-LEEME.md](https://github.com/pl0n3r/brvtal/blob/main/revision-juridica/00-LEEME.md); [revision-juridica/paquete-completo.md](https://github.com/pl0n3r/brvtal/blob/main/revision-juridica/paquete-completo.md); [docs/privacidad/aviso-privacidad.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/aviso-privacidad.md); [docs/privacidad/canal-derechos.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/canal-derechos.md); [docs/privacidad/politica-tratamiento.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/politica-tratamiento.md); [docs/privacidad/registro-tratamientos.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/registro-tratamientos.md); [docs/privacidad/retencion.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/retencion.md); [docs/privacidad/terminos-condiciones.md](https://github.com/pl0n3r/brvtal/blob/main/docs/privacidad/terminos-condiciones.md).
- **Resumen de datos:** 19 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** La fuente docs/privacidad/canal-derechos.md es borrador; confirmar contacto, legitimación y plazos.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: BRVTAL

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| admin_activity | contact / admin_activity_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_identity | contact / admin_access | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_password | authentication / admin_authentication | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_password_reset | authentication / admin_password_recovery | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_recovery_codes | authentication / admin_account_recovery | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_recovery_delivery | contact / admin_account_recovery_delivery | review_required | external_mailbox_review_required | smtp_mail_provider |
| admin_session | authentication / admin_session | review_required | session_30d | ninguno declarado — NO VERIFICADO |
| admin_totp | authentication / admin_mfa | review_required | review_required | ninguno declarado — NO VERIFICADO |
| admin_totp_pending | authentication / admin_mfa_challenge | review_required | session_30d | ninguno declarado — NO VERIFICADO |
| auth_password_rate_limit | usage / auth_abuse_prevention | review_required | review_required | ninguno declarado — NO VERIFICADO |
| auth_totp_rate_limit | usage / mfa_abuse_prevention | review_required | review_required | ninguno declarado — NO VERIFICADO |
| contact_delivery | contact / contact_delivery | review_required | external_mailbox_review_required | ninguno declarado — NO VERIFICADO |
| contact_rate_limit | usage / contact_abuse_prevention | review_required | review_required | ninguno declarado — NO VERIFICADO |
| internal_analytics_events | usage / internal_usage_analytics | review_required | review_required | ninguno declarado — NO VERIFICADO |
| legacy_admin_activity_ip | usage / legacy_admin_activity_audit | review_required | review_required | ninguno declarado — NO VERIFICADO |
| public_gtm_measurement | usage / public_usage_measurement | review_required | review_required | google_analytics |
| staff_identity | identification / staff_administration | review_required | staff_account_lifecycle | ninguno declarado — NO VERIFICADO |
| staff_contact | contact / staff_invitation_and_recovery | review_required | staff_account_lifecycle | ninguno declarado — NO VERIFICADO |
| staff_access_metadata | usage / staff_security_monitoring | review_required | review_required | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: BRVTAL
1. **Pregunta:** ¿Qué aviso aplica a formularios, cuentas, cookies y Google Analytics?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Es necesario consentimiento para GTM y medición y cómo revocarlo?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Qué retención concreta aplica a sesiones, TOTP, logs y correo SMTP?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Qué contratos o transferencias existen con SMTP, Google y hosting?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Cómo se ejercen derechos de visitantes y equipo editorial?
   **Respuesta del abogado (pendiente):** ____________________________________________

## FactoryRunner

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/FactoryRunner/blob/main/datos.yml) — blob 86484722ffeafc544400f7ae946b039be468e375; [revision-juridica/00-LEEME.md](https://github.com/pl0n3r/FactoryRunner/blob/main/revision-juridica/00-LEEME.md); [revision-juridica/paquete-completo.md](https://github.com/pl0n3r/FactoryRunner/blob/main/revision-juridica/paquete-completo.md); [docs/privacidad/aviso-privacidad.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/aviso-privacidad.md); [docs/privacidad/canal-derechos.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/canal-derechos.md); [docs/privacidad/politica-tratamiento.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/politica-tratamiento.md); [docs/privacidad/registro-tratamientos.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/registro-tratamientos.md); [docs/privacidad/retencion.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/retencion.md); [docs/privacidad/terminos-condiciones.md](https://github.com/pl0n3r/FactoryRunner/blob/main/docs/privacidad/terminos-condiciones.md).
- **Resumen de datos:** 1 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** La fuente docs/privacidad/canal-derechos.md es borrador; confirmar contacto, legitimación y plazos.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: FactoryRunner

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| runner_location_metadata | location / runner_routing | review_required | review_required | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: FactoryRunner
1. **Pregunta:** ¿La ubicación del runner identifica una persona o solo infraestructura?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Qué base y límites de conservación requiere el uso interno?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Cuál es el rol jurídico entre Runner, ControlBot y Hostinger?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Cómo se atienden derechos sobre location y logs si los hubiera?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Qué aprobaciones faltan antes de operación real y go-live?
   **Respuesta del abogado (pendiente):** ____________________________________________

## AutoFactory

- **Estado:** documented_not_legally_approved; fase construccion.
- **Fuentes para revisión:** [inventario de tratamientos](https://github.com/pl0n3r/AutoFactory/blob/main/datos.yml) — blob 972dc40d5cc384fe2fab0eb0487bc51746358056; [README arquitectura local](https://github.com/pl0n3r/AutoFactory/blob/main/README.md).
- **Resumen de datos:** 9 tratamientos técnicos, finalidades y bases sin validación jurídica; revisar categorías, consentimiento, minimización y medidas de seguridad.
- **Derechos:** AutoFactory no dispone de carpeta revision-juridica ni de docs/privacidad en el corte; el abogado debe decidir si requiere aviso y canal propios.
- **Terceros/retención:** proveedores listados a continuación son declarativos y no prueban contratos ni transferencia; cada plazo técnico requiere revisión.

### Inventario técnico: AutoFactory

| Tratamiento | Categoría / finalidad | Base declarada (sin aprobación) | Retención técnica (no plazo legal) | Proveedores declarados (no validados) |
| --- | --- | --- | --- | --- |
| local_continuation_settings | usage / local_user_configured_chat_continuation | review_required | review_required | ninguno declarado — NO VERIFICADO |
| local_diagnostic_log | usage / local_error_diagnostics | review_required | maximum_300_events_and_7_days_on_append | ninguno declarado — NO VERIFICADO |
| local_adaptive_metrics | usage / local_performance_adaptation | review_required | review_required | ninguno declarado — NO VERIFICADO |
| local_account_message_budget | usage / local_proactive_provider_limit_avoidance | review_required | rolling_configured_window_maximum_512_send_and_limit_events_per_account; Factory policy expires by closed-contract timestamp | ninguno declarado — NO VERIFICADO |
| local_pending_send_state | usage / local_duplicate_send_prevention | review_required | review_required | ninguno declarado — NO VERIFICADO |
| local_factory_control_profile_credential | authentication / local_factory_control_profile_pairing_credential | review_required | maximum_24_hours_by_contract | ninguno declarado — NO VERIFICADO |
| planned_factory_control_heartbeat_status | usage / planned_factory_control_status_sync | review_required | no_remote_transmission_or_persistence_until_D-061 | ninguno declarado — NO VERIFICADO |
| local_factory_control_instance_identity | authentication / local_factory_control_instance_identity_and_consent | review_required | identity_local_until_extension_data_removed; grant_maximum_24_hours_and_revocable | ninguno declarado — NO VERIFICADO |
| planned_shared_adaptive_learning | usage / cross_browser_recovery_learning | review_required | local_queue_maximum_200_events; remote_retention_not_active | ninguno declarado — NO VERIFICADO |

### Preguntas concretas: AutoFactory
1. **Pregunta:** ¿Qué telemetría de chats y pestañas podría ser dato personal?
   **Respuesta del abogado (pendiente):** ____________________________________________
2. **Pregunta:** ¿Qué conservación aplica a diagnósticos, mensajes y credenciales efímeras?
   **Respuesta del abogado (pendiente):** ____________________________________________
3. **Pregunta:** ¿Qué roles corresponden a navegadores, ChatGPT y conexión futura con ControlBot?
   **Respuesta del abogado (pendiente):** ____________________________________________
4. **Pregunta:** ¿Cómo informar sobre control local y derecho de supresión?
   **Respuesta del abogado (pendiente):** ____________________________________________
5. **Pregunta:** ¿Necesita aviso y canal propios o bastan términos internos del dueño?
   **Respuesta del abogado (pendiente):** ____________________________________________

## Consolidación y decisiones pendientes

- [ ] Verificar responsable, NIT, domicilio, poderes y canal de derechos de los siete proyectos fuera del repositorio público.
- [ ] Validar bases, consentimiento, roles responsable/encargado y destinatarios/transferencias efectivas tratamiento por tratamiento.
- [ ] Definir plazos y métodos de retención/eliminación, excepciones legales, procedimientos y respuestas de derechos.
- [ ] Revisar borradores existentes y decidir textos específicos faltantes de AutoFactory, sin suponer que no aplica la privacidad por ser local.
- [ ] Obtener dictamen jurídico con jurisdicción, fecha, versión de cada producto, persona autorizante y diferencias pendientes.
- [ ] Reconciliar los inventarios antes de cualquier decisión del dueño para live y datos reales.

**Resultado actual:** documento preparatorio, no ejecutar cambios productivos, pagos, firma, tratamiento de terceros ni go-live.
