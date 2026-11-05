# Condor — verificación de decisiones 03

> Issue #1546. Fase construccion. Contraste de veinte filas D-040-S07…D-041-S06 con fuentes y pruebas existentes; PR del issue #1546 aún sujeto a merge. No acredita producción, go-live, WCAG ni datos reales. `docs/matriz-cobertura.md` queda intacta.

## Método

- `TESTIGO_ACOTADO`: aserción exacta sobre fuente y regresión existente, o guarda estructural local. Su presencia no equivale a CUBIERTO del requisito completo.
- `BRECHA`: subcontrol sin prueba exacta suficiente. Conserva `NO_VERIFICABLE` y enlaza una hoja hija canónica para implementación posterior.
- La suite `tests/test_verificacion_decisiones_03.py` falla cuando faltan los testigos, sus pruebas de regresión o las cinco referencias a hojas hijas. PHPUnit/Playwright y producción se verifican por separado, nunca por inferencia de esta hoja.

| Decisión | Estado | Evidencia o hoja hija | Límite específico |
| --- | --- | --- | --- |
| `D-040-S07` · Secretos | TESTIGO_ACOTADO | `config/packages/framework.yaml` · `tests/test_salida_secretos_accesos.py` | APP_SECRET y catálogo de secretos sintético, sin verificar gestión real de credenciales. |
| `D-040-S08` · Headers | BRECHA | [Condor #1603](https://github.com/pl0n3r/Condor/issues/1603) | Faltan negativos HTTP de CSP, nosniff y políticas de respuesta. |
| `D-040-S09` · Logging | TESTIGO_ACOTADO | `src/Infrastructure/Observability/SentryEventSanitizer.php` · `tests/test_ci_sentry_privacy_contract.py` | Sentry sanea información sensible; pendiente correlación y retención global. |
| `D-040-S10` · Auditoría | TESTIGO_ACOTADO | `tests/test_billing_audit_envelope.py` · `tests/test_billing_audit_envelope.py` | Billing tiene registro mínimo probado; no cubre todos los eventos administrativos. |
| `D-040-S11` · Backups | TESTIGO_ACOTADO | `scripts/backup-persistent-files.py` · `tests/test_backup_persistent_files.py` | Restore sintético de media; no acredita DB diaria, offsite ni 30 días reales. |
| `D-040-S12` · Rehearsal | TESTIGO_ACOTADO | `scripts/simular_reversion_apagado.py` · `tests/test_ci_salida_reversion_simulacro.py` | Simulacro de apagado/rollback sintético; RPO/RTO y ensayo real pendientes. |
| `D-040-S13` · Dependencias | TESTIGO_ACOTADO | `.github/dependabot.yml` · `tests/test_dependabot_policy.py` | Regresión Dependabot; auditorías npm/Composer siguen regidas por CI. |
| `D-040-S14` · Destructivo | TESTIGO_ACOTADO | `scripts/post-deploy.sh` · `tests/test_post_deploy.py` | Post-deploy rechaza SQL destructivo en sandbox, no es permiso productivo. |
| `D-040-S15` · Tenant/IDOR | TESTIGO_ACOTADO | `tests/php/Security/NewModulesTenantIsolationTest.php` · `tests/test_new_modules_tenant_isolation.py` | Rechazo cross-tenant probado en servicios concretos, no todos los módulos. |
| `D-040-S16` · XSS | BRECHA | [Condor #1603](https://github.com/pl0n3r/Condor/issues/1603) | No existe regresión exacta de HTML enriquecido editable. |
| `D-040-S17` · Uploads | BRECHA | [Condor #1604](https://github.com/pl0n3r/Condor/issues/1604) | No hay negativos completos de contenido/MIME y nombres opacos. |
| `D-040-S18` · SSRF | BRECHA | [Condor #1604](https://github.com/pl0n3r/Condor/issues/1604) | No hay negativos de loopback, private, redirects y metadata. |
| `D-040-S19` · Concurrencia | TESTIGO_ACOTADO | `src/Application/Inventory/StockTransferService.php` · `tests/test_stock_transfers_api.py` | Transferencia de stock probada, no todo pago o efecto externo. |
| `D-040-S20` · Gate seguridad | TESTIGO_ACOTADO | `config/packages/security.yaml` · `tests/test_ci_security_http_session.py` | CSRF y sesión HTTP probados, no todos los vertical slices. |
| `D-041` · Sistema diseño | TESTIGO_ACOTADO | `frontend/admin/AdminShell.tsx` · guarda estructural de la suite nueva | Shell administrativo estructurado; no certifica WCAG. |
| `D-041-S02` · Tokens | TESTIGO_ACOTADO | `frontend/admin/admin.css` · guarda estructural de la suite nueva | CSS usa spacing semántico; faltan pruebas integrales de escalas. |
| `D-041-S03` · Densidad | TESTIGO_ACOTADO | `frontend/admin/admin.css` · guarda estructural de la suite nueva | Grid responsive presente; no cubre cada tabla y formulario. |
| `D-041-S04` · Componentes | BRECHA | [Condor #1605](https://github.com/pl0n3r/Condor/issues/1605) | Faltan primitives reutilizables validadas para foco y teclado. |
| `D-041-S05` · Navegación | TESTIGO_ACOTADO | `frontend/admin/AdminShell.tsx` · guarda estructural de la suite nueva | aria-current indica la página actual; seguridad backend separada. |
| `D-041-S06` · Responsive | TESTIGO_ACOTADO | `frontend/admin/admin.css` · guarda estructural de la suite nueva | Controles táctiles 44px; falta prueba real viewport 360px. |

## Resultado reproducible

- Ejecutar `python3 -m unittest tests.test_verificacion_decisiones_03`; la suite valida 20 IDs, 15 testigos y 5 brechas; el CI canónico valida las suites funcionales originales.
- Las hojas hijas #1603 (headers/XSS), #1604 (uploads/SSRF), #1605 (primitives/viewport) son trabajo nuevo, no evidencia fusionada ni permiso de go-live.
- El PR que cierre #1546 aporta el rastro de revisión de la hoja, no evidencia de sistemas productivos ni el estado `CUBIERTO` de la matriz hasta reconciliación posterior.
