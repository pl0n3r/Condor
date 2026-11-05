# GEST-FLUJO-1…5 · verificación de recorridos integrales (#1541)

Corte: 2026-11-05, Condor en `construccion`. Fuente normativa
`docs/gestion-empresarial-integral.md`. Matriz original
`docs/matriz-cobertura.md` **intacta** (claim de consolidación ajeno).
Esta entrega es una **verificación de brechas**, no una implementación de los
cinco flujos completos ni un certificado jurídico o productivo.

## Contraste ejecutable por cada una de las 18 filas

| Fila | Contrato real parcialmente ejecutable | Testigo previo de PHP sintético | Veredicto integral y límite sin ocultar | Hoja hija |
| --- | --- | --- | --- | --- |
| GEST-FLUJO-1 | `src/Application/Compliance/NormativeApplicability.php` | `tests/test_cobertura_condor_13.py` | **NO_VERIFICABLE**: faltan permisos integrales y revisión profesional del perfil; no se atribuye E2E | #1620 |
| GEST-FLUJO-1-P01 | `src/Application/Compliance/NormativeApplicability.php` | `tests/test_cobertura_condor_13.py` | **NO_VERIFICABLE**: falta creación persistida de perfil por entidad/sede y RBAC; no se atribuye E2E | #1620 |
| GEST-FLUJO-1-P02 | `src/Application/Compliance/NormativeMatrix.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: faltan fuentes oficiales vigentes y revisor autenticado; no se atribuye E2E | #1620 |
| GEST-FLUJO-1-P03 | `src/Application/Compliance/ComplianceEvidenceEvaluation.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: faltan obligaciones asignadas y vencimientos persistidos; no se atribuye E2E | #1620 |
| GEST-FLUJO-1-P04 | `src/Application/Compliance/ComplianceEvidenceEvaluation.php` | `tests/test_cobertura_condor_13.py` | **NO_VERIFICABLE**: falta subida de evidencia y decisión autorizada por actor distinto; no se atribuye E2E | #1620 |
| GEST-FLUJO-1-P05 | `src/Application/Compliance/ComplianceAttentionProjection.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta comprobar que alertas no bloquean actos legales indispensables; no se atribuye E2E | #1620 |
| GEST-FLUJO-2 | `src/Application/Documents/DocumentDossierVersions.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta expediente documental HTTP/DB y política integral; no se atribuye E2E | #1620 |
| GEST-FLUJO-2-P01 | `src/Application/Documents/DocumentApprovalRetention.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta aplicar RBAC/retención de documentos cargados; no se atribuye E2E | #1620 |
| GEST-FLUJO-2-P02 | `src/Application/Documents/DocumentDossierVersions.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta carga y hash persistentes con autor/proceso; no se atribuye E2E | #1620 |
| GEST-FLUJO-2-P03 | `src/Application/Documents/DocumentApprovalRetention.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta aprobación humana competente y revisión de historial real; no se atribuye E2E | #1620 |
| GEST-FLUJO-2-P04 | `src/Application/Documents/DocumentApprovalRetention.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: faltan legal hold, exportación, eliminación y auditoría real; no se atribuye E2E | #1620 |
| GEST-FLUJO-3 | `src/Application/Risk/RiskRegisterControls.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: falta ciclo conectado de riesgo, incidente, calidad y mejora; no se atribuye E2E | #1620 |
| GEST-FLUJO-3-P01 | `src/Application/Risk/RiskIncidentTreatment.php` | `tests/test_cobertura_condor_14.py` | **NO_VERIFICABLE**: falta registrar incidentes persistentes con permisos y severidad; no se atribuye E2E | #1620 |
| GEST-FLUJO-3-P02 | `src/Application/Risk/RiskRegisterControls.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: falta responsable real y evaluación de control con evidencia; no se atribuye E2E | #1620 |
| GEST-FLUJO-3-P03 | `src/Application/Quality/QualityNonconformityActions.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: falta verificación de eficacia y cierre humano aprobado; no se atribuye E2E | #1620 |
| GEST-FLUJO-3-P04 | `src/Application/Reporting/TenantCsvReportService.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: falta tablero sensible con RBAC y supresión de PII/denuncias; no se atribuye E2E | #1620 |
| GEST-FLUJO-4 | `src/Application/Commercial/EntitlementSnapshot.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: bloqueo jurídico B de #742/D-059/D-063 impide ejecución laboral; no se atribuye E2E | #1620 |
| GEST-FLUJO-5 | `src/Application/Assets/AssetRegisterLifecycle.php` | `tests/test_cobertura_condor_15.py` | **NO_VERIFICABLE**: faltan transacciones y permisos entre compras, activos y operación; no se atribuye E2E | #1620 |

## Evidencia real y qué NO demuestra

- `tests/test_verificacion_gest_flows_e2e.py` comprueba los **18 IDs**,
  su fuente documental y las 18 filas `NO_VERIFICABLE` de la matriz; rechaza
  filas faltantes, duplicadas, sustituidas por `CUBIERTO`, fuente ilegible
  y reportes sin una brecha exacta y hoja hija.
- Ejecuta tests anteriores de **PHP real** por `unittest.TestCase.run`, sin
  stub de servicios: `CoberturaCondor13Tests::test_contrato_principal`
  (aplicabilidad/versionado/evidencia y preview), sus negativos,
  `CoberturaCondor15Tests::test_contrato_principal` (riesgos,
  acciones correctivas, documentos, activos, entitlements, exportación
  minimizada), sus negativos, y `CoberturaCondor14Tests::test_falla_cerrado`.
  Usa `php_call` de #1333 para contrastar explícitamente el código real de
  normativa y expediente/documentos: dos versiones encadenadas, aprobación
  separada, nunca autocierre ni borrado.
- **Regresión previa a cargo de otro claim:** el test heredado
  `tests/test_cobertura_condor_14.py::CoberturaCondor14Tests.test_contrato_principal`
  falló en una ejecución de diagnóstico sobre el `main` de este corte:
  `RiskIncidentTreatment` devolvió `structurally_reviewable=false` donde
  el fixture anterior esperaba `true`. Se documenta como regresión previa
  **no acreditada**, sin editar fuente/testigo fuera de la V3; la hoja hija
  #1620 debe reconciliar el contrato y añadir su negativo, no fingir verde.
  Esa prueba anterior no es puerta canónica del nuevo PR si CI no la ejecuta.
- Los contratos `test_cobertura_condor_13/14/15.py` son *slices locales*
  y deliberadamente **no simulan una transacción HTTP autenticada de cinco
  módulos**, un actor real, base jurídica, persistencia, permisos UI,
  integridad probatoria, efecto legal o uso en producción.

## Huecos funcionales por recorrido

**FLUJO-1 (perfil y cumplimiento).** `NormativeApplicability` y
`ComplianceEvidenceEvaluation` rechazan fuentes/fechas/tenant incorrectos
y una evidencia pendiente nunca hace `CUMPLE`. Quedan creación efectiva de
perfil/obligaciones, autoridad del revisor, fuentes oficiales, alerta
no obstructiva y auditoría persistente. Próxima integración: #1620,
vinculada al épico de cumplimiento #802.

**FLUJO-2 (documento controlado).** `DocumentDossierVersions` y
`DocumentApprovalRetention` ejercitan hashes ficticios, versiones y
separación de autor/aprobador, sin certificar carga real, acceso según
clasificación, retención obligatoria, legal hold, descarga íntegra o eliminación
con auditoría. La hija de dominio [#1571](https://github.com/pl0n3r/Condor/issues/1571)
requiere integración autorizada; el contraste integral va a #1620.

**FLUJO-3 (riesgo, calidad y mejora).** `RiskRegisterControls`,
`QualityNonconformityActions` y el CSV minimizado ejercitan scoring,
plazos, control de tenants y censura básica. No prueban asignación humana
persistida, evaluación de eficacia, cierre autorizado ni tablero con
RBAC y segregación de denuncias/PII. Hojas de dominio #1582 y #1583;
contraste conectado #1620.

**FLUJO-4 (ciclos laborales).** **BLOQUEADO**. El cierre administrativo
de #742 no revoca la opción B: D-059 y D-063 requieren nueva autorización
jurídica y del dueño. `EntitlementSnapshot` comprueba que una capacidad
deshabilitada permanece cerrada; no se abren nómina, seguridad social,
contratos ni tratamiento de datos reales. La hija de diseño #1577
tampoco autoriza activación. Ninguna prueba de este PR debe sustituir
la decisión del dueño.

**FLUJO-5 (abastecimiento, activos y operación).** Hay flujo comercial
parcial en `PurchaseRequestPreview`, `PurchaseOrderService`,
`AssetRegisterLifecycle` y `OperationsProcessController`, pero no
una transacción íntegra entre compras, activo y proceso. Faltan validación
autenticada del aprobador, evaluación de proveedor, vinculación de soportes
y reconciliación multiempresa; #1578 y #1620 contienen la continuación.

## Reproducción, CI y reversión

```sh
python3 -m unittest -v tests.test_verificacion_gest_flows_e2e
python3 -m unittest -q tests.test_cobertura_condor_13 tests.test_cobertura_condor_15
git diff --check
```

El CI remoto debe terminar con `CI Factory v1 / Validar` y `Validar`
terminales sobre el HEAD exacto. Si el patrón del workflow no descubre
`test_verificacion_gest_flows_e2e.py`, el resultado local del comando
anterior **no se atribuye** al workflow. No se usa go-live, no se importan
datos reales, no hay proveedores externos, gasto, secretos, migraciones ni
acciones irreversibles. Revertir el squash revierte exclusivamente el test
y este informe, no habilita ningún flujo productivo.
