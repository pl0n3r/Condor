# Condor #1547 — verificación ejecutable D-041-S07 a D-044-S04

**Corte:** código de `Condor/main` al iniciar #1547; prueba nueva `tests/test_verificacion_decisiones_04.py` y PR asociado a `Closes #1547`. No se modificó `docs/matriz-cobertura.md`. Esta tabla **no** promueve el veredicto integral de la matriz por sí misma: el test ejecuta regresiones ya implementadas, y cada decisión aún necesita la tríada código → test → PR fusionado para reconciliación posterior.

**Evidencia:** `python3 -m unittest discover -s tests -p 'test_verificacion_decisiones_04.py' -v`. Los testigos invocan métodos de prueba contra los módulos/Twig/HTTP loopback reales; no cuentan nombres de métodos como éxito y rechazan skips. El resultado confirma únicamente el **subcontrato offline** indicado, no conformidad WCAG total, release externa ni operación live. La ausencia de evidencia independiente sigue `UNKNOWN`. Para faltantes operativos se abrió **[Condor#1594](https://github.com/pl0n3r/Condor/issues/1594)** (hija bloqueada; requiere autoridad humana). Las investigaciones existentes #1327 y #1328 permanecen referencia histórica, no prueba de producción.

| Decisión | Test ejecutado exacto / brecha | Contrato comprobable local | Pendiente integral |
| --- | --- | --- | --- |
| D-041-S07 | LOCAL_PARTIAL: test_public_site_accessibility.AccesibilidadSitioPublicoTests.test_detecta_salto_de_encabezados_y_control_sin_label | Parser de HTML real: headings, labels y negativos | B: WCAG del shell completo/navegador; Condor#1594 |
| D-041-S08 | LOCAL_PARTIAL: test_landing_empezar.LandingEmpezarTests.test_network_map_encodes_each_stop_state_in_its_shape_and_text | Estado visible de recorrido en Twig | B: todos los estados de UI administrativa; Condor#1594 |
| D-041-S09 | LOCAL_PARTIAL: test_landing_empezar.LandingEmpezarTests.test_primary_call_to_action_leads_to_the_plan_configurator | CTA público real y destino de navegación | B: microcopy transversal es-CO; Condor#1594 |
| D-043 | LOCAL_PARTIAL: test_release_evidence.ReleaseEvidenceTests.test_unknown_observation_state_never_promotes_release | Reducer de release evita promover UNKNOWN | B: recorrido release integral; Condor#1594 |
| D-043-S01 | LOCAL_PARTIAL: test_release_evidence.ReleaseEvidenceTests.test_deploy_observed_state_is_not_promoted_by_complete_checks | Separación DEPLOY_OBSERVED/producción | B: transición real; Condor#1594 |
| D-043-S02 | LOCAL_PARTIAL: test_release_evidence.ReleaseEvidenceTests.test_manifest_uses_canonical_version_and_exact_sha | Manifest de versión/SHA exactos | B: metadata observada en hosting; Condor#1594 |
| D-043-S03 | LOCAL_PARTIAL: test_observar_release.ReleaseObserverTests.test_js_con_mime_legacy_de_hostinger_es_aceptado | Compatibilidad MIME JS del observador | B: arranque/release real en shared hosting; Condor#1594 |
| D-043-S04 | LOCAL_PARTIAL: test_release_evidence.ReleaseEvidenceTests.test_manifest_uses_canonical_version_and_exact_sha | Identidad de artefacto desde fuente canónica | B: reconstrucción binaria reproducible en CI/hosting; Condor#1594 |
| D-043-S05 | LOCAL_PARTIAL: test_release_evidence.ReleaseEvidenceTests.test_migration_requires_migration_and_cache_checks | Manifest exige migración y cache | B: backup/migración productiva; Condor#1594 |
| D-043-S06 | LOCAL_PARTIAL: test_observar_release.ReleaseObserverTests.test_sha_distinto_no_se_reintenta_ni_visita_paginas | Observer rechaza SHA ajeno | B: observación del deploy real; Condor#1594 |
| D-043-S07 | LOCAL_PARTIAL: test_observar_release.ReleaseObserverTests.test_release_exacta_y_smoke_publico | Smoke HTTP loopback del observer | B: smoke real contra dominio; Condor#1594 |
| D-043-S08 | LOCAL_PARTIAL: test_authenticated_production_smoke.AuthenticatedProductionSmokeTests.test_tenant_auth_dashboard_and_cross_scope_denial | CSRF, tenant y permisos en cliente sintético | B: sesión real aislada y autorizada; Condor#1594 |
| D-043-S09 | LOCAL_PARTIAL: test_ci_throughput_report.ThroughputTests.test_elapsed_seconds | Timestamps observables y duración | B: serie completa Issue→producción; Condor#1594 |
| D-044-S01 | LOCAL_PARTIAL: test_ci_external_uptime_run_freshness.ExternalUptimeRunFreshnessTests.test_successful_run_does_not_imply_healthy_product | Freshness no inventa salud productiva | B: latencia/errores productivos; Condor#1594 |
| D-044-S02 | LOCAL_PARTIAL: test_ci_external_uptime_watch.ExternalUptimeWatchTests.test_open_alert_is_deduplicated_by_marker | Deduplicación de incidente/alerta | B: entrega real a receptor externo; Condor#1594 |
| D-044-S03 | LOCAL_PARTIAL: test_backup_persistent_files.PersistentFileBackupTest.test_round_trip_verifies_bytes_nested_paths_and_private_permissions | Round-trip de archivos aislados con bytes/permisos | B: restore DB+archivos/objetivos reales; Condor#1594 |
| D-044-S04 | LOCAL_PARTIAL: test_ci_throughput_report.ThroughputTests.test_regression_requires_three_samples | Regresión de duración CI con baseline mínima | B: CWV p75 y API p95 reales; Condor#1594 |
| D-042-S05 | UNKNOWN: C: política de avance por slices, no función autónoma | Sin test autónomo íntegro; evidencia editorial o externa no fabricable | B: evidencia actual de roadmap/slices; Condor#1594 |
| D-043-S10 | UNKNOWN: B: primer deploy V0.1.0 histórico sin prueba offline válida | Sin test autónomo íntegro; evidencia editorial o externa no fabricable | B: recibo Hostinger/identidad/smoke histórico; Condor#1594 |
| D-044 | UNKNOWN: C: programa integral de operaciones sin entrypoint único | Sin test autónomo íntegro; evidencia editorial o externa no fabricable | B: health/alertas/recovery/performance integrales; Condor#1594 |

## Qué evidencia prueba y qué no

- **A (LOCAL_PARTIAL):** las aserciones locales llaman código/fixtures reales o servidor HTTP loopback y comprueban negativos observables. Si se revierte la invariante que ejercitan, fallan; no extrapolan a todas las superficies de Condor.
- **B (UNKNOWN):** navegador integral, identidad exacta realmente desplegada, smoke de usuarios de prueba, datos de throughput, backup/restore productivo, alertas y CWV/p95 requieren evidencia externa auténtica. El cierre de código o un CI verde **no** lo suple.
- **C (UNKNOWN):** D-042-S05 y D-044 integral son programas/reglas editoriales; exigen valoración trazable por slice y operación, no un único método autónomo ficticio. #1327/#1328 registraron esta clasificación parcial.

**Seguridad y reversión:** sólo archivos nuevos, fixtures sintéticos y pruebas locales; ningún token, dato de cliente, llamada a producción, deploy, SQL ni gasto. Para revertir el corte, revertir su squash. La decisión de promoción/merge permanece sujeta a V3, Validar exact-HEAD, seguridad y puertas Factory.