# Condor #1544 — verificación de decisiones D-003 a D-028

**Corte ejecutable en construcción**, independiente de producción, con claims exactos `docs/verificacion/decisiones-01.md` y `tests/test_verificacion_decisiones_01.py`. Reserva V3 `f9ce3cca-e8a9-4819-920e-930fba01f47e`. Hija explícita [#1608](https://github.com/pl0n3r/Condor/issues/1608). Este documento **no altera** `docs/matriz-cobertura.md` ni promueve `NO_VERIFICABLE` a `CUBIERTO`.

**Ejecución reproducible:** `python3 -m unittest discover -s tests -p 'test_verificacion_decisiones_01.py' -v`. Cada testigo se carga, ejecuta y exige exactamente un test exitoso, sin errores ni skips. Los testigos utilizan código real de release, throughput, PHP de dominio y contratos de fuentes del repositorio; no basta con que exista un nombre de test. La tabla separa explícitamente test local de evidencia integral externa. Una reversión que altere las aserciones del testigo provoca fallo en su ejecución. Los 17 resultados `LOCAL_PARTIAL` son de subcontrato, no acreditan requisitos transversales completos.

| Decisión | Resultado local | Testigo exacto ejecutado | Qué ejercita | Pendiente integral / hoja |
| --- | --- | --- | --- | --- |
| `D-003` | LOCAL_PARTIAL | `test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal` | locale es-CO, Twig y modelo de sedes con fixtures PHP | Negociación integral de idioma en HTTP y administración |
| `D-005` | LOCAL_PARTIAL | `test_release_identity_guard.ReleaseIdentityGuardTests.test_existing_tag_on_other_commit_fails_closed_without_mutation` | guarda real que rechaza reutilizar una identidad de release | Deploy observado con identidad y versión únicos |
| `D-006` | LOCAL_PARTIAL | `test_brechas_condor_05.BrechasCondor05Tests.test_contrato_principal` | contrato local del snapshot README y paridad sintética | Paridad con recibo autenticado de deploy |
| `D-009` | LOCAL_PARTIAL | `test_release_identity_guard.ReleaseIdentityGuardTests.test_pr_title_version_must_match_canonical_version` | validación real del sufijo de PR y versión canónica | Aplicación universal a Issues, releases y milestones |
| `D-011` | UNKNOWN | — | Marca oficial y nombre corto: falta test HTTP/navegador transversal que compruebe Condor App, Condor y SEO en todas las superficies | [Hija #1608](https://github.com/pl0n3r/Condor/issues/1608) |
| `D-012` | LOCAL_PARTIAL | `test_contraste_d_l3.ContrasteDL3Tests.test_contrato_principal` | wiring de coordinador Factory y comandos con aislamiento por issue | Carreras y expiraciones reales de reservas V3 |
| `D-013` | LOCAL_PARTIAL | `test_brechas_condor_06.BrechasCondor06Tests.test_contrato_principal` | relay local, shape de anotaciones y señales conservadoras | Roundtrip real, idempotencia y política vigente de relay manual |
| `D-014` | LOCAL_PARTIAL | `test_ci_throughput_report.ThroughputTests.test_regression_requires_three_samples` | clasificador real de muestras de telemetría | Observador post-CI y artefactos reales exact-SHA |
| `D-015` | LOCAL_PARTIAL | `test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal` | clasificación de gates y trazas de harness de prueba | Ejecución E2E completa y artifacts en runner |
| `D-016` | LOCAL_PARTIAL | `test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal` | contrato local de selección E2E/Chrome estable | Versión Chrome observada y comparativa de tiempos exact-HEAD |
| `D-018` | LOCAL_PARTIAL | `test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal` | defaults y separación por tenant sobre entidades PHP | Configuración transversal por empresa en recorrido HTTP completo |
| `D-020` | LOCAL_PARTIAL | `test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal` | invariantes de configuración y sedes con fixtures | Divulgación progresiva integral en navegador |
| `D-021` | LOCAL_PARTIAL | `test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal` | máquina de estados real: pedido/pago/cumplimiento | Integración de persistencia y recorrido HTTP completo |
| `D-022` | LOCAL_PARTIAL | `test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal` | motor de workflow real y reglas tenant-bound | Persistencia, autorización y ejecución HTTP transversales |
| `D-023` | UNKNOWN | — | Portabilidad Hostinger→AWS: falta contrato reproducible de arranque/entorno y sustitución del storage sin inferir un deploy | [Hija #1608](https://github.com/pl0n3r/Condor/issues/1608) |
| `D-024` | LOCAL_PARTIAL | `test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal` | baseline de seguridad y clasificación CI con PHP real | Validación integral de runtime/DB/backup |
| `D-025` | UNKNOWN | — | Tenant por ruta y dominio personalizado: falta test HTTP real de host/slug, tenant ajeno, host desconocido y canonicalización | [Hija #1608](https://github.com/pl0n3r/Condor/issues/1608) |
| `D-026` | LOCAL_PARTIAL | `test_contraste_d_l4.ContrasteDL4Tests.test_contrato_principal` | contrato sintético de modelo comercial acotado | No fijar planes/precios ni inferir cobros live |
| `D-027` | LOCAL_PARTIAL | `test_contraste_d_l1.ContrasteDL1Tests.test_contrato_principal` | entidades PHP reales de sedes, precios, canales e inventario | Recorrido integrado de precio efectivo y stock |
| `D-028` | LOCAL_PARTIAL | `test_contraste_d_l2.ContrasteDL2Tests.test_contrato_principal` | entidad Customer, categoría tenant-bound y guardas de controlador | Recorrido integrado catálogo/servicios/personal/entidades |

## Límites de la evidencia

- **17 LOCAL_PARTIAL:** se ejecuta código/prueba existente con aserción real; fallar, omitir o saltar el testigo rechaza la evidencia. No es prueba nueva de go-live ni de cada ruta administrativa.
- **3 UNKNOWN:** D-011, D-023 y D-025 requieren pruebas integrales con entrypoints reales. La hija [#1608](https://github.com/pl0n3r/Condor/issues/1608) es el follow-up explícito y permanece `estado: bloqueado` hasta completar este corte; jamás se acredita DNS, hosting ni modo live por su creación.
- **CI:** el PR de #1544 solo podrá fusionarse con `CI Factory v1 / Validar` terminal verde en HEAD exacto, reserva V3 válida y gates de seguridad. La prueba es ejecutable por el comando anterior; un workflow verde **no constituye por sí solo recibo de ejecución de esta suite** si su discovery no la incluye.
- **No productivo:** fixtures sintéticos, sin credenciales, información personal real, requests a proveedores, escrituras en producción, go-live o cambios de planes. Reversión por revert de los dos archivos de este PR.

## Referencias trazables

- [Especificaciones del producto](../../ESPECIFICACIONES.md), decisiones D-003 a D-028.
- [Matriz existente](../matriz-cobertura.md), que permanece sin modificación y continúa como autoridad de veredicto integral.
- [Hoja hija #1608](https://github.com/pl0n3r/Condor/issues/1608), brechas de marca, portabilidad y resolución de dominio.
- PR del propio [#1544](https://github.com/pl0n3r/Condor/issues/1544): se consignará el merge y SHA real solo después de verificarlo.
