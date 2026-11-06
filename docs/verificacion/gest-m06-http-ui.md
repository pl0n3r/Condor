# M06 · integración HTTP/UI sintética (#1629)

Fase **construcción**. Contrato del servidor: solo `dev`/`test`, `CONDOR_LEGAL_SYNTHETIC_API=1`, `CONDOR_PRODUCTION_STAGE=construccion`; bandera OFF ⇒ 404, sin exposición pública. Requiere identidad de sesión, scope tenant/sede servidor, `settings.view` y `settings.create/update` con CSRF para los únicos cambios de escenario. La sesión solo conserva `demo`/`vacio` por scope; no se aceptan reglas, nombres o datos reales.

| Fila | GET autorizado y proyección existente | Evidencia exigida |
| --- | --- | --- |
| GEST-M06-S01 | `/legal-compliance/perfil` · NormativeApplicability F0 | HTTP autenticado/DEV, UI |
| GEST-M06-S02 | `/legal-compliance/catalogo` · NormativeMatrix F1a | HTTP autenticado/DEV, UI |
| GEST-M06-S03 | `/legal-compliance/matriz` · NormativeMatrix F1a | HTTP autenticado/DEV, UI |
| GEST-M06-S04 | `/legal-compliance/calendario` · NormativeReviewProjection F1b + ComplianceAttentionProjection F2a | HTTP autenticado/DEV, UI |
| GEST-M06-S06 | `/legal-compliance/evaluaciones` · ComplianceEvidenceEvaluation | HTTP autenticado/DEV, UI |
| GEST-M06-S07 | `/legal-compliance/auditorias` · ComplianceAuditCandidateProjection F2b | HTTP autenticado/DEV, UI |

Prefijo de rutas: `/api/v1/branches/{branchId}`. `POST/PATCH /legal-compliance/fixtures` seleccionan solo escenario ficticio, no mutan datos legales. Pruebas: `tests/php/Http/LegalCompliance/LegalComplianceHttpTest.php` (Symfony WebTestCase con identidad, RBAC, cross-tenant, CSRF, bandera y payload); `tests/test_m06_http_ui_integration.py` (wiring/UX estático). Validación de navegador E2E y CI final deben verificarse contra ejecución real, no inferirse de este documento. Sin certificación legal, aprobación automática, notificaciones, gasto ni go-live.

### Validación de integración y build (issue #1629)

La pantalla M06 comparte la compilación del administrador con las demás áreas React. Para que la comprobación de frontend y Playwright sea reproducible, `public/build/admin.js` debe generarse con `npm run build:admin` desde el mismo HEAD; el artefacto queda reclamado explícitamente por la V3 renovada después de fusionarse #1644. El guard D-069 impide publicar builds si la reserva, el SHA o los claims no coinciden. No editar el bundle minificado manualmente.

La vista mantiene escenarios únicamente sintéticos y los estados `loading`, `empty`, `error` y `permission-denied`. CI y las pruebas de navegador son la evidencia de ejecución, no este documento.

**Evidencia de reconciliación del bundle:** el job `Frontend TypeScript / build` del run [42251291258](https://github.com/pl0n3r/Condor/actions/runs/42251291258) publicó mediante el guard V3 autorizado `public/build/admin.js` en el commit [`cca101b6`](https://github.com/pl0n3r/Condor/commit/cca101b6b6400aa9928bcff7ef68e8c14b06460f). Los controles globales del PR deben ejecutarse nuevamente sobre el HEAD resultante; el resultado de un job previo no acredita `Validar` en commits nuevos.
