# Condor #1638 — prueba normativa sintética y recibos F0–F6 / AC01–07

Alcance: fase construccion, datos estrictamente ficticios, sin credenciales, consultas a fuentes reales, empleados, documentos médicos, pagos ni producción. Depende de #1620 y su PR #1795 fusionado. Este reporte delimita evidencia de código: NO declara cumplimiento jurídico colombiano, ejecución productiva ni liberación del roadmap integral. Las 18 filas de abajo siguen NO_VERIFICABLE hasta demostrar todos sus recorridos reales, revisión profesional y puertas humanas. La matriz de cobertura pertenece exclusivamente a #1553 y no se edita aquí.

## Contrato ejecutable y trazabilidad

- tests/e2e/test_gest_norm_phases.py::GestNormPhasesE2ETests::test_normative_http_mariadb_and_versioned_rules ejecuta dos pruebas PHPUnit reales materializadas temporalmente: testNormativeProvenanceVersionAndReviewFailClosed (clases reales NormativeApplicability, NormativeMatrix y NormativeReviewProjection) y testNormativeHttpMariaDbOwnerIsolationAndNoAutoApproval (KernelBrowser Symfony, sesión, firewall, CSRF, Doctrine y MariaDB pdo_mysql con rollback).
- tests/contract/test_gest_norm_phases_runtime.py es el puente CI: importa sin alteraciones los mismos dos tests Python desde tests/e2e. El trabajo canónico descubre tests/contract/test_*.py tras preparar Composer y MariaDB, pero no tests/e2e directamente. Ausencia de PHP, simple-phpunit, esquema o conexión produce fallo, nunca skip/PASS sintético.
- tests/e2e/test_gest_norm_phases.py::GestNormPhasesE2ETests::test_18_rows_are_explicit_and_never_falsely_promoted verifica las 18 filas exactas, ausencia de duplicados y alcance fail-closed. No es sustituto de las pruebas conductuales.
- Comando local, solamente con Composer, esquema test y MariaDB descartable disponibles: python3 -m unittest -v tests.e2e.test_gest_norm_phases.
- El recibo de CI exact-HEAD requiere identificar SHA del PR, URL del run, job de integración que realmente ejecutó estos tests, y CI Factory v1 / Validar terminal en success. El commit o este documento no son por sí solos un recibo ejecutado. Prohibido inferir READY del nombre del job.
- PHPUnit crea tenants y branches A/B más dueño y lector, comprueba host HTTP real con sesión y permisos, deniega CSRF ausente, campos de datos personales, escenarios no ficticios, cross-tenant y roles insuficientes. Todas las entidades creadas se revierten en una transacción SQL; se verifica que ambas desaparezcan de condor_tenant tras rollback.
- La fuente oficial usada es el hostname example.invalid, marcador sintético de formato. No demuestra que una norma exista, que una firma profesional la aprobó, ni que una versión fue verificada realmente. Los identificadores de reviewer y evidence son también ficticios.

## Mapa por fila: testigo realmente ejecutable y brecha integral

| ID | Estado integral | Testigo parcial en este PR o antecedente | Brecha para acreditación completa |
| --- | --- | --- | --- |
| GEST-NORM-01 | NO_VERIFICABLE | NormativeApplicability rechaza fuente y emisor incompletos; HTTP perfil expone procedencia de fixture | No hay inventario jurídico oficial contrastado ni revisión profesional |
| GEST-NORM-02 | NO_VERIFICABLE | Version v1, fechas y rechazo de regla caducada o verificada en el futuro | Falta versionado operativo persistido y actualización de normas oficiales |
| GEST-NORM-03 | NO_VERIFICABLE | Contexto municipio/sector altera APLICA frente a NO_APLICA y UNKNOWN fuerza REQUIERE_REVISION | Faltan otros perfiles y condiciones oficiales por entidad |
| GEST-NORM-07 | NO_VERIFICABLE | NormativeReviewProjection exige reviewer, fecha, evidencia y EN_REVISION; rechaza CUMPLE arbitrario | No hay firma/revisor profesional autenticado ni aprobación legal real |
| GEST-F0 | NO_VERIFICABLE | Proyección F0 real y perfil/catálogo HTTP de fixture con source/version/reviewer | No existe catálogo normativo versionado administrado desde fuente oficial |
| GEST-F1 | NO_VERIFICABLE | Matriz GET con reglas OBLIGATORIO y PROHIBIDO, aislamiento tenant, PENDIENTE/EN_REVISION sin cierre | La matriz real de obligaciones/autoridades no está enlazada a documentos de empresa |
| GEST-F2 | NO_VERIFICABLE | GET calendario/evaluaciones/auditorias HTTP dentro de sesión owner; automáticamente aprobado/cerrado falso | Persistencia transversal, alertas, auditoría y controles reales no acreditados |
| GEST-F3 | NO_VERIFICABLE | M06 sintético desactivado responde 404; ningún test activa SG-SST sensible | Bajo #742/B, D-059 y D-063 no hay permiso para expedientes médicos; falta recorrido negativo especializado |
| GEST-F4 | NO_VERIFICABLE | Ningún fixture ni ruta laboral activada; pruebas solo usan tenants/branches ficticios | RR. HH., nómina y firma continúan bloqueados por #742/B y D-059/D-063 |
| GEST-F5 | NO_VERIFICABLE | Antecedente #1620/PR #1795 prueba riesgo y calidad con endpoints separados y rollback | Gobierno, activos, finanzas, proveedores y operación no están conectados integralmente |
| GEST-F6 | NO_VERIFICABLE | /admin auténtico en Symfony ofrece sesión y token CSRF; HTTP owner/denegaciones verificables | Falta auditoría accesibilidad/es-CO integral, aprobación experta y piloto autorizado |
| GEST-AC-01 | NO_VERIFICABLE | Dos perfiles sintéticos de municipio diferente producen resultados de aplicabilidad distintos | Reglas para perfiles reales revisadas y criterios completos faltan |
| GEST-AC-02 | NO_VERIFICABLE | No se acepta CUMPLE como estado de review; flags HTTP de auto-aprobación y auto-cierre falsos | Revisión profesional real y flujo de certificación no habilitados |
| GEST-AC-03 | NO_VERIFICABLE | Tenant ajeno excluido del motor; HTTP ajeno 404; lector sin permisos 403 | Validación E2E de todas las entidades, sedes y roles pendiente |
| GEST-AC-04 | NO_VERIFICABLE | Fuente exige issuer/article/version/official_url y fecha; caducidad retorna REQUIERE_REVISION | Procedencia oficial/actualización persistida con responsable comprobable pendiente |
| GEST-AC-05 | NO_VERIFICABLE | Fuente de verdad de resultado es PHP NormativeApplicability, expuesta por controlador real | Fuente jurídica externa autenticada y cambio normativo propagado a obligaciones pendiente |
| GEST-AC-06 | NO_VERIFICABLE | Sin empleados ni datos reales; API M06 denegada con feature flag apagado | Denegación especializada SG-SST/laboral y decisión sucesora a #742/B pendientes |
| GEST-AC-07 | NO_VERIFICABLE | HTTP cubre vacío, CSRF denegado, 403/404, caché no-store y dashboard owner | Falta recorrido UI accesible es-CO, errores y carga en todos los slices |

## Negativos, riesgos y fronteras

REQUIERE_REVISION es la salida conservadora de reglas no revisadas, fuera de vigencia, fuente/version incompletas, perfil desconocido, revisión inválida, revisión duplicada o fecha de evidencia imposible. Las filas de otros tenants no se revelan. Un resultado APLICA no constituye CUMPLE ni prueba que el texto ficticio corresponde a una norma real.

Fases F3 y F4: ningún resultado positivo ni dato laboral/médico se genera. La decisión B de #742, D-059 y D-063 impiden activar tratamiento y publicación. La prueba de cierre de M06 con flag apagado NO sustituye una futura prueba especializada de esos módulos; no se inventa ese recibo.

F5 y F6: el testigo parcial de #1620 y el HTML /admin no equivalen a un flujo conectado completo ni auditoría accesible es-CO. Las pruebas nuevas no duplican código ni claims de #1620, #1637 o #1553. Las 41 filas originales del reporte #1543 no cambian de estado.

La estrategia de reversión es revertir el squash de estas tres rutas sin afectar módulos de producción. No se habilita go-live, se modifican flags productivas, se crean usuarios de cliente ni se promueven valores a VALIDADO_EN_PRODUCCION. Los flags y las entidades sintéticas existen solo durante la ejecución aislada del test.
