# Condor #1535 — verificación M10: responsable y evidencia

Alcance exacto: GEST-M10-S03 (responsable) y GEST-M10-S07 (evidencia). Fuente: \`docs/gestion-empresarial-integral.md\` (M10). Esta hoja **no** modifica \`docs/matriz-cobertura.md\` ni declara validez jurídica, persistencia de responsables, prueba de producción o cobertura integral inexistente.

## Contraste y decisión por submódulo

| ID | Evidencia existente y comprobación del PR | Estado y brecha exacta |
| --- | --- | --- |
| GEST-M10-S03 · responsable | \`AssetRegisterLifecycle::project\` obliga \`fixture:responsible/*\` para \`ASIGNAR\`, aisla tenant+empresa, rechaza duplicados y no aprueba/cierra. \`AssetVerificationM10Test::testResponsibleRefRequiresSyntheticIdentityAndCorrectScope\`; \`testActualApiServiceUsesCompanyScopeAndDeniesUnsafePayloads\` verifica el alcance real del servicio con DBAL sintética. | **BRECHA/NO_VERIFICABLE integral**: la API \`AssetApiService\` no expone ni persiste \`responsible_ref\` (lo rechaza con allowlist); el componente React simula asignación solo en memoria. Se requiere hoja hija de endpoint/persistencia sintética autorizada y UI integrada. |
| GEST-M10-S07 · evidencia | \`AssetRegisterLifecycle::project\` y \`AssetRecord::transition\` exigen \`fixture:evidence/*\` para solicitar baja, nunca aprueban o cierran automáticamente. \`AssetVerificationM10Test::testEvidenceIsMandatoryForReviewableDisposalAndNeverAutoApproves\`, \`testDomainDeniesUnauthorizedChangesAndInvalidEvidence\` y \`testActualApiServiceUsesCompanyScopeAndDeniesUnsafePayloads\` comprueban el rechazo de entradas reales y el registro de auditoría sintética. | **BRECHA/NO_VERIFICABLE integral**: referencia sintética no es archivo/evidencia versionada ni cadena de custodia, ni demuestra vista autenticada completa de loading/empty/error/permission-denied. Se requiere hoja hija de interfaz y flujo de evidencia. |

## Límite HTTP y UI (AC-02)

La ruta versionada existente en \`AssetsController\` ya exige \`inventory.view\` para GET, \`inventory.create\` para POST, \`inventory.update\` y CSRF para PATCH, y extrae tenant/empresa del usuario y sede autorizada (\`BranchApiSupport::authorizedBranchScope\`). Fuera de \`test\`, está cerrada salvo \`dev\` con \`CONDOR_ASSETS_SYNTHETIC_API=1\` y \`CONDOR_PRODUCTION_STAGE=construccion\`. Los tests de aplicación adjuntos verifican filtrado multiempresa, payload no permitido y referencias ficticias, **no** son prueba HTTP real contra sesión ni prueba de permisos en navegador.

\`frontend/admin/AssetsManagement.tsx\` presenta \`empty\` y \`permission-denied\` pero es un simulador in-memory solo DEV; **no** implementa loading/error de fetch porque no consulta API, ni persiste responsable. Este archivo **no está reclamado** en #1535. La solución posterior está registrada como hoja hija [#1558](https://github.com/pl0n3r/Condor/issues/1558), con dependencia de #1535, claims propios de frontend/infraestructura y estado bloqueado hasta cerrar este PR. No editar el componente ni ampliar los paths actuales.

## Reproducción, seguridad y reversión

- \`vendor/bin/phpunit --filter AssetVerificationM10Test\`: contrato sintético de proyección, mutaciones y aislamiento del servicio; no usa usuarios de negocio ni red ni credenciales.
- La suite existente \`python3 -m unittest tests.test_assets_http_api tests.test_assets_persistence tests.test_assets_admin_ui\` conserva aserciones de controladores, PHP real, servicio, auditoría y UI de demostración.
- **AC-04:** exige resultado terminal \`CI Factory v1 / Validar\` en success sobre el HEAD del PR; una ejecución local no basta.
- Riesgo/reversión: únicamente prueba + informe bajo claims autorizados, reversible mediante revert del squash. Sin migrations, datos reales, go-live, proveedores, gasto ni cambios productivos. No autoriza promover a CUBIERTO las filas pendientes de integración.
