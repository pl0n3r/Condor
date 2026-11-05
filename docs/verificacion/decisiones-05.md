# Condor · verificación de decisiones · lote 05 · Issue #1548

**Corte:** 2026-11-05 · fase `construccion` · `ESPECIFICACIONES.md` D-044-S05…D-049-S04.
Esta hoja no modifica `docs/matriz-cobertura.md` ni acredita deploy, live, DNS,
certificados o decisiones humanas. La tríada código/test/PR **MERGED** solo existe
cuando se fusione el PR correspondiente. Los criterios de aceptación de #1548
no cambian el estado integral de las filas por el simple hecho de escribir esta hoja.

**Clasificación:** `A` subcontrato local ejecutable con fixture sintético;
`B` contrato local parcial que exige fuente externa/HTTP/navegador;
`C` mantenimiento, gobierno o portabilidad cuya operación longitudinal
no se observa en las pruebas. **Las 20 decisiones conservan NO_VERIFICABLE
a nivel integral.** Un testigo causal parcial no prueba el programa completo.

| Decisión | Código/workflow parcial (ruta real) | Testigo local y aserción exacta | Clase | Brecha no acreditada |
| --- | --- | --- | --- | --- |
| `D-044-S05` | `src/Http/Controller/SeoSettingsController.php` | `tests/test_seo_settings_http.py` · `test_acceptance` | A parcial | SSR/sitemap/robots por cada dominio requieren E2E con canonical y host reales — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S06` | `src/Application/Observability/FunctionalSignalReport.php` | `tests/test_cobertura_condor_09.py` · `test_contrato_principal` | A parcial | instrumentación integral de onboarding e inventario requiere eventos reales acotados — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S07` | `.github/workflows/seguridad.yml` | `tests/test_security_workflow_contract.py` · `test_owner_exact_decision_commands_use_versioned_factory_security` | C parcial | faltan calendario mensual de dependencias y revisión arquitectónica trimestral acreditados — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S08` | `scripts/post-deploy.sh` | `tests/test_post_deploy.py` · `test_construction_reconciles_pending_schema_before_cache` | A parcial | smoke/backup/rehearsal y telemetría operativos requieren recibos verificables — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S09` | `scripts/post-deploy.sh` | `tests/test_post_deploy.py` · `test_live_fails_closed_without_migrating_or_touching_cache` | A parcial | migración y esquema desplegado no se infieren de un sandbox — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S10` | `.github/workflows/seguridad.yml` | `tests/test_security_workflow_contract.py` · `test_consumer_code_is_never_checked_out_or_executed` | C parcial | falta revisión trimestral de amenazas autorizada y hallazgos accionables — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-044-S11` | `scripts/backup-persistent-files.py` | `tests/test_post_deploy.py` · `test_backup_failure_prevents_migration_and_cache` | C parcial | portabilidad Hostinger a otro proveedor no ensayada ni medida — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-045` | `src/Application/Seo/IndexNowNotifier.php` | `tests/test_indexnow_notifier.py` · `test_acceptance` | A parcial | falta integración integral con HTTP de publicación, DNS real y CSP por tenant — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-045-S01` | `src/Application/Seo/IndexNowNotifier.php` | `tests/test_indexnow_notifier.py` · `test_acceptance` | A parcial | envío IndexNow a proveedor real no autorizado en este lote — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-045-S02` | `src/Application/Seo/TagManagerSettings.php` | `tests/test_tag_manager_settings.py` · `test_acceptance` | A parcial | snippets GTM en navegadores/hosts reales y consentimiento requieren E2E — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-045-S03` | `src/Infrastructure/Http/SecurityHeadersSubscriber.php` | `tests/test_tag_manager_settings.py` · `test_php_lint_and_no_external_transport` | A parcial | CSP HTTP multitenant y ausencia de secretos requieren integración completa — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-046` | `src/Application/Identity/ProvisionPlatformOwner.php` | `tests/test_provision_platform_owner_command_unit.py` · `test_acceptance` | B parcial | invariante singleton/roles debe acreditarse con carrera y base real de pruebas — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-047` | `src/Http/Controller/SharedDiagnosticController.php` | `tests/test_shared_diagnostic_http.py` · `test_acceptance` | A parcial | retención, revocación, auditoría y 5xx productivos requieren evidencia real — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-048` | `frontend/admin/PlatformOwnerApp.tsx` | `tests/test_platform_owner_api_permissions.py` · `test_acceptance` | B parcial | shell compartido y barra de contexto requieren sesión de navegador autenticada — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-048-S02` | `frontend/admin/PlatformOwnerApp.tsx` | `tests/test_brechas_condor_18.py` · `test_estados_unknown_explicitos` | B parcial | progreso visible en runtime real y navegación read-only no observados — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-049` | `src/Infrastructure/Tenancy/TenantResolver.php` | `tests/test_cobertura_condor_11.py` · `test_contrato_principal` | B parcial | servidor de dominio personalizado, propiedad DNS y TLS aún no acreditados — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-049-S01` | `src/Infrastructure/Tenancy/TenantResolver.php` | `tests/test_cobertura_condor_11.py` · `test_contrato_principal` | A parcial | navegación real a slug y host personalizado requiere E2E — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-049-S02` | `src/Domain/Organization/Entity/TenantDomain.php` | `tests/test_cobertura_condor_11.py` · `test_falla_cerrado` | A parcial | unicidad real y estado activo requieren persistencia de pruebas y DNS — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-049-S03` | `src/Domain/Organization/Entity/TenantDomain.php` | `tests/test_cobertura_condor_11.py` · `test_sin_datos_reales` | B parcial | DNS/TLS y prueba de propiedad del host exigen recibos externos — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |
| `D-049-S04` | `src/Infrastructure/Tenancy/TenantResolver.php` | `tests/test_cobertura_condor_11.py` · `test_falla_cerrado` | A parcial | Host header HTTP/HTTPS en reverse proxy real requiere prueba externa — seguimiento integral: [#1606](https://github.com/pl0n3r/Condor/issues/1606) |

## Aserciones ejecutables y negativos

- `python3 tests/test_verificacion_decisiones_05.py -v` reejecuta todos los testigos únicos de la tabla, sobre sus implementaciones reales existentes. La suite falla si falta un archivo/método, una ejecución se omite o un contrato positivo/negativo falla.
- Se exigen PHP 8.5, dependencias Composer y base de pruebas sintética cuando los testigos existentes lo requieren. Una ausencia de entorno devuelve **UNKNOWN/fallo**, nunca PASS. No se efectúan mutaciones de producción ni llamadas a DNS, Google Tag Manager o IndexNow.
- Los negativos existentes prueban rechazo de host desconocido/no verificado y cruce de tenants, GTM sin consentimiento/ID inválido, esquema pendiente en `live`, backup fallido, y accesos administrativos indebidos. Cada testigo tiene alcance limitado a su contrato local; ningún resultado sustituye seguridad end-to-end en Hostinger.
- El clasificador de cambios confirma que la hoja y su test pertenecen a `pruebas_base`; la ejecución propia de la suite se declara explícita y **no se presume** que `CI Factory v1 / Validar` por sí solo haya ejecutado esta suite. Exigir ambos resultados y evidencia exact-HEAD.
- Quedan pendientes los contratos íntegros de mantenimiento periódico, amenazas/portabilidad, carrera DB de propietario, navegación autenticada y propiedad DNS/TLS. Las brechas se conservan como `NO_VERIFICABLE` para posterior trabajo con autoridad y prueba externa, con hoja hija de seguimiento [#1606](https://github.com/pl0n3r/Condor/issues/1606), sin duplicar [#1363](https://github.com/pl0n3r/Condor/issues/1363), [#1364](https://github.com/pl0n3r/Condor/issues/1364), [#1496](https://github.com/pl0n3r/Condor/issues/1496) ni [#616](https://github.com/pl0n3r/Condor/issues/616), y sin inventar estado de producción.

**Seguimiento:** hoja hija [#1606](https://github.com/pl0n3r/Condor/issues/1606), planificada y dependiente de #1548. La consolidación posterior de la matriz permanece en [#1553](https://github.com/pl0n3r/Condor/issues/1553), sin promover cobertura por inferencia.

**Resultado conservador:** 20/20 decisiones inventariadas, pruebas locales parciales reutilizadas, 0 decisiones declaradas integralmente verificadas. Se conserva la deuda de evidencia de `docs/matriz-cobertura.md`.
