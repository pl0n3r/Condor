# Condor #1539 · verificación de seguridad de la información M14

**Alcance:** revisión exact-main de GEST-M14-S01…S08, solamente en fase `construccion` y con fixtures sintéticos. Esta entrega **no cambia** `docs/matriz-cobertura.md`, no valida producción ni convierte un control parcial en un módulo completo. Una prueba de unidad o un check verde no constituye evidencia de flujo integral.

## Evidencia contrastada (código real y prueba)

| ID | Evidencia disponible / aserción verificable | Estado y siguiente hoja |
| --- | --- | --- |
| **GEST-M14-S01 · RBAC** | `BranchAuthorization::permissions` rechaza branches ajenas antes de consultar DB; `BranchAuthorization::require` exige membresía y permiso del delegado. `GestM14SecurityContractTest::testRolePermissionsRejectForeignTenantBranchBeforeRepositoryLookup`, `testDelegatedMemberWithoutRoleCannotGrantUserPermissions`; `BranchAccessControllerTest::testDelegatedAdminCannotGrantPermissionsTheyDoNotHave`. | **PARCIAL / NO_VERIFICABLE integral**: `require` omite cotejo tenant/branch cuando el usuario es OWNER. Corregir y probar ruta HTTP en [#1588](https://github.com/pl0n3r/Condor/issues/1588). No se presume explotación del endpoint. |
| **GEST-M14-S02 · accesos** | `config/packages/security.yaml` restringe `/api/v1` y `/admin`; `PasswordSecurityController` exige identidad para cambios y CSRF en POST. `GestM14SecurityContractTest::testAnonymousPasswordMutationRequiresAuthentication`, `testAnonymousLoginAndPasswordRecoveryDoNotCachePersonalData` prueban firewall y cabeceras del login/recovery. `SecurityHttpSessionNegativesTest` verifica caducidad y CSRF en dos tenants sintéticos. | **PARCIAL / NO_VERIFICABLE integral**: falta contrato completo de UI loading/empty/error/permission-denied para gestión de accesos y negativos HTTP de roles; [#1588](https://github.com/pl0n3r/Condor/issues/1588). |
| **GEST-M14-S03 · revisiones** | Hay `Role`, `Membership`, `BranchRoleAssignment` y `BranchAuthorization`; son autorización vigente, **no** una revisión periódica. | **BRECHA / NO_VERIFICABLE**: falta recertificación de permisos (solicitud, dictamen, expiración, auditoría) aislada por tenant; [#1589](https://github.com/pl0n3r/Condor/issues/1589). |
| **GEST-M14-S04 · auditoría** | `AuditEvent` tiene tenant y `BranchApiSupport::audit` registra acciones asociadas al actor en persistencia existente. | **PARCIAL / NO_VERIFICABLE integral**: no hay consulta/retención/proceso de auditoría de seguridad recertificable con pruebas HTTP y estados UI; [#1589](https://github.com/pl0n3r/Condor/issues/1589). |
| **GEST-M14-S05 · copias** | Existen contratos de backup/restauración operativos fuera de esta hoja; no se observó recibo de restauración real del hosting asociado a M14. | **BRECHA / NO_VERIFICABLE**: falta evidencia autorizada de backup+restore verificable; [#1590](https://github.com/pl0n3r/Condor/issues/1590). Sin tocar backups ni producción aquí. |
| **GEST-M14-S06 · incidentes** | Los eventos técnicos de logging/auditoría no prueban workflow de incidentes de seguridad. | **BRECHA / NO_VERIFICABLE**: falta registro, clasificación, transición de estado y consultas multi-tenant con pruebas sintéticas; [#1589](https://github.com/pl0n3r/Condor/issues/1589). |
| **GEST-M14-S07 · continuidad** | La presencia de scripts y checks CI no acredita recuperación tras pérdida ni RTO/RPO reales. | **BRECHA / NO_VERIFICABLE**: falta ensayo de continuidad en entorno autorizado con recibo, SHA y fallos claros; [#1590](https://github.com/pl0n3r/Condor/issues/1590). |
| **GEST-M14-S08 · privacidad** | `PrivacyRightsController` entrega una página informativa, con placeholders seguros ante configuración ausente o inválida. No recibe solicitudes ni usa datos reales. | **PARCIAL / NO_VERIFICABLE integral**: falta flujo autorizado de derechos y negativa cross-tenant con UI completa; [#1591](https://github.com/pl0n3r/Condor/issues/1591). No se declara cumplimiento jurídico. |

## Cambio verificable de esta hoja

- `SecurityController::login` responde a la pantalla anónima con `Cache-Control: no-store, private` y `Referrer-Policy: no-referrer`, igualando el manejo de las páginas de recuperación sin modificar el firewall.
- `tests/php/Security/GestM14SecurityContractTest.php` ejercita rutas de login/recovery, bloqueo de POST anónimo y negativas reales de `BranchAuthorization` (sin membresía, branch ajena, falta de rol).
- Verificación requerida: `vendor/bin/phpunit --filter GestM14SecurityContractTest`, PHPStan y Rector aplicables, más `CI Factory v1 / Validar` terminal `success` sobre el HEAD del PR. Un check `pending` o `skipped` no equivale a éxito.
- El fallo adicional detectado en `BranchAuthorization::require` está **fuera** de los claims V3 de #1539; no modificarlo de forma lateral. #1588 mantiene una ruta de reparación con tests negativos. Para S03…S08, las hojas #1589–#1591 son el seguimiento de brechas; la matriz sigue sin promoción.

## Riesgo y reversión

Solo endurecimiento de cabeceras y pruebas/documentación: reversible por revert del squash. No se crean endpoints de auditoría ficticios, credenciales, datos reales, backups, deploy, go-live, gastos ni validaciones legales automáticas. El estado de cada fila es conservador y se concilia en la hoja de consolidación una vez fusionados los PR de brechas.
