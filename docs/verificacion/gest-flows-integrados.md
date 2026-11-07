# Condor #1620 — recorrido sintético HTTP y brechas GEST-FLUJO-1…5

**Alcance:** fase `construccion`; ejecución contra Symfony real, sesión y firewall, Doctrine y MariaDB descartable. No hay datos, usuarios, firmas, proveedores, pagos ni operaciones productivas. No hay declaración integral de cumplimiento jurídico.

## Recibo ejecutable — corte de código (no producción)

- **Prueba:** `tests/e2e/test_gest_flows_integrados.py::GestFlowsIntegradosTests::test_authenticated_http_mariadb_transaction` invoca PHPUnit `GestSyntheticIntegratedHttpTest::testSyntheticFlowRealHttpAndMariaDbRollback` materializado **temporalmente**. Es un test real, no un mock HTTP ni una afirmación de resultado antes del CI.
- **Comando local:** `python3 -m unittest -v tests.e2e.test_gest_flows_integrados`. **Descubrimiento CI:** `tests/contract/test_gest_flows_runtime.py` exporta los mismos dos tests originales dentro de `python3 -m unittest discover -s tests/contract -p 'test_*.py'`, tras Composer, migraciones y MariaDB descartable. Se requiere PHP CLI, `vendor/bin/simple-phpunit`, Symfony, conexión `pdo_mysql` y esquema migrado. Si falta algún requisito, la prueba falla; nunca se convierte en `skip`/PASS ficticio.
- **Identidad del recibo:** solo puede citarse `CI Factory v1 / Validar` tras que el run termine en `success` sobre el SHA exacto de un PR fusionable; adjuntar entonces el URL del run y su SHA. **Este documento no es ese recibo** ni prueba de producción.
- **Aislamiento:** un tenant/entidad/sede A y un tenant/entidad/sede B, propietario y lector con rol `settings.view`; un único `KernelBrowser` con cookies reales. La transacción real de MariaDB ejecuta `rollback` en `finally`; el test comprueba la desaparición de ambos tenants.
- **Recorrido conectado que existe:** consulta vacía de cumplimiento → crea evidencia ficticia (solo con token CSRF) → crea documento clasificado en el mismo branch → vincula `fixture:evidence/flujo` al documento → solicita revisión sin cierre → verifica secuencia del endpoint `trace`. Las acciones emiten `AuditEvent` por tenant, y el test afirma que se registraron.
- **Proyecciones separadas, no enlace empresarial:** crea un riesgo sintético y una no conformidad sintética mediante sus **endpoints** y rechaza los estados `CERRADO`/`APROBADO`. No se afirma relación persistida automática con el documento ni entre riesgo y calidad. Aún falta integración transversal real.
- **Negativos:** anónimo sin sesión, 403 sin CSRF, campo `tenant_id` inesperado rechazado, lectura cross-tenant 404 en cuatro controladores, rol de solo lectura incapaz de crear y ausencia de autoaprobación. No se verifica UI universal ni integraciones productivas.

## Estado por fila: testigo parcial frente a brecha integral

Todas las filas mantienen **`NO_VERIFICABLE`** hasta evidencia funcional end-to-end y autoridad real, no solo el test sintético de esta hoja. La matriz `docs/matriz-cobertura.md` no se modifica desde #1620.

| Fila | Testigo concreto o límite | Estado integral y brecha |
| --- | --- | --- |
| `GEST-FLUJO-1` | HTTP de evidencia y CSRF con tenant A | `NO_VERIFICABLE`: falta perfil empresarial completo con reglas y obligaciones persistidas |
| `GEST-FLUJO-1-P01` | Se usan legal entities y branches sintéticos en MariaDB | `NO_VERIFICABLE`: falta creación y mantenimiento del perfil completo con CIIU, régimen y permisos de UI |
| `GEST-FLUJO-1-P02` | Servicio `NormativeApplicability` disponible como proyección F0, no unido al HTTP del recorrido | `NO_VERIFICABLE`: falta procedencia oficial y revisión profesional vinculada a sesión |
| `GEST-FLUJO-1-P03` | La evidencia usa `fixture:obligation/flujo` | `NO_VERIFICABLE`: no hay obligación empresarial persistida, responsable y vencimiento enlazados |
| `GEST-FLUJO-1-P04` | Evidencia ficticia se vincula a documento en estado revisable | `NO_VERIFICABLE`: falta aprobación profesional con dos identidades y flujo real de evidencia |
| `GEST-FLUJO-1-P05` | `AuditEvent` real en transacción descartada | `NO_VERIFICABLE`: alertas y auditoría transversal no acreditadas end-to-end |
| `GEST-FLUJO-2` | Documento HTTP con clasificación y trazabilidad de tres eventos | `NO_VERIFICABLE`: versiones, aprobaciones, retención y legal hold completos pendientes |
| `GEST-FLUJO-2-P01` | `classification=INTERNA` entra por HTTP con RBAC | `NO_VERIFICABLE`: política de retención/acceso no demostrada integralmente |
| `GEST-FLUJO-2-P02` | Se crea referencia sintética `fixture:doc/flujo` | `NO_VERIFICABLE`: no hay archivo versionado real con hash e integridad persistida |
| `GEST-FLUJO-2-P03` | `request-review` produce `PENDIENTE_REVISION`, `can_close=false` | `NO_VERIFICABLE`: falta aceptación/rechazo por aprobador diferente y con autoridad legal |
| `GEST-FLUJO-2-P04` | Solo se prueba rastro `trace`; ningún delete real | `NO_VERIFICABLE`: legal hold, exportación, retención y borrado seguro no integrados |
| `GEST-FLUJO-3` | HTTP de riesgos y calidad en el mismo tenant, sin enlace entre sistemas | `NO_VERIFICABLE`: falta flujo enlazado incidente → control → eficacia → cierre |
| `GEST-FLUJO-3-P01` | Riesgo y no conformidad ficticios creados por controladores reales | `NO_VERIFICABLE`: severidad, causa y evidencia persistidas no demostradas |
| `GEST-FLUJO-3-P02` | Rechaza estado `CERRADO` de riesgo | `NO_VERIFICABLE`: control con responsable y evaluación de efectividad real no probado |
| `GEST-FLUJO-3-P03` | No conformidad deniega `APROBADO`, permite `EN_REVISION` | `NO_VERIFICABLE`: no hay flujo con revisor distinto y eficacia documentada |
| `GEST-FLUJO-3-P04` | No hay exposición de nombres ni PII en fixtures; 404 cross-tenant | `NO_VERIFICABLE`: falta dashboard agregado con control real de datos sensibles |
| `GEST-FLUJO-4` | **Solo DENEGADO:** decisión B de [#742](https://github.com/pl0n3r/Condor/issues/742) | `NO_VERIFICABLE`: #1577 y D-059/D-063; no RR. HH. real, nómina, firmas ni PILA |
| `GEST-FLUJO-5` | Los endpoints existentes no se fuerzan a emitir una cadena falsa de compras/activos/operación | `NO_VERIFICABLE`: falta recorrido conectado con permisos, aceptación, inventario y proveedor |

## Límites y acciones restantes

La prueba intencionalmente **no** autentica integraciones con terceros ni simula un `VALIDATED_IN_PRODUCTION`. No modifica `docs/matriz-cobertura.md`, no crea controles o usuarios reales, no sobreescribe los archivos de #1621/#1638, no activa F3/F4 ni atribuye autorizaciones a un profesional. La hoja [#1638](https://github.com/pl0n3r/Condor/issues/1638) queda pendiente para fuente y reglas normativas versionadas, F0–F6 y AC01–07 **después de** fusionar esta hoja, con reserva V3 propia.

**Reversión:** revertir el squash de los tres archivos de #1620; no hay migración, secreto, cambio productivo ni estado duradero que revertir.
