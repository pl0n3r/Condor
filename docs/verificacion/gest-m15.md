# Condor · GEST-M15 · Gobierno corporativo (Issue #1540)

Corte: 2026-11-05. Solo construcción y datos sintéticos. El API está
condicionado a `test` o `dev` con `CONDOR_GOVERNANCE_SYNTHETIC_API=1`
y `CONDOR_PRODUCTION_STAGE=construccion`. No usarlo para sociedades,
socios reales, permisos legales, firmas, decisiones jurídicas ni go-live.
`docs/matriz-cobertura.md` permanece bajo la hoja de consolidación.

## Testigo del servidor y brechas honestas

`tests/php/Application/Governance/GestM15VerificationTest.php` ejecuta
el servicio PHP real `GovernanceApiService` y el registro inmutable
`GovernanceRecord`, con aserciones de aislamiento por tenant/branch,
IDs ficticios, clave exacta, permisos declarados, CSRF y estados no aprobados.
La revisión del controlador es estática: **no prueba** una sesión HTTP real.

| Fila | Testigo en código y prueba | Resultado actual / brecha |
| --- | --- | --- |
| GEST-M15-S01 · socios | `shareholder`, `socio`; `testS01SociosSinteticosAislados` | **PARCIAL**: registro ficticio aislado, sin cuotas, libro de socios o identidad legal |
| GEST-M15-S04 · facultades | `faculty`, `facultad`; `testS04FacultadesSinDelegacionLegal` | **PARCIAL**: revisión simulada, sin delegación/validación de facultades legales |
| GEST-M15-S07 · autorizaciones | `authorization`, `autorizacion`; `testS07AutorizacionesSinAprobacionLegal` | **PARCIAL**: estado de revisión sin autorización societaria ni firma |
| GEST-M15-S08 · decisiones | `decision`; `testS08DecisionesSinCierreAutomatico` | **PARCIAL**: bitácora sintética sin acta aprobada, quorum ni decisión vinculante |

Los endpoints existentes `GET/POST/PATCH /api/v1/branches/{branchId}/governance`
ya pasan por `authorizedBranchScope`, `inventory.view/create/update`,
CSRF en escrituras, separación de sesión mediante hash de tenant+branch
y auditoría de servidor; los nuevos tipos usan esas mismas puertas.
`APROBADO`, `CERRADO`, `AUTORIZADO` y `FIRMADO` se rechazan.
No hay peticiones externas ni cambios de producción.

**Brecha pendiente:** `frontend/admin/GovernanceManagement.tsx` actualmente
simula órganos solo en memoria; no consume estos endpoints ni prueba
los estados loading, empty, error y permission-denied con respuestas HTTP
autenticadas. Hoja hija creada: [#1564](https://github.com/pl0n3r/Condor/issues/1564),
bloqueada hasta fusionar #1540. Integrará HTTP/UI con pruebas end-to-end,
sin invadir los claims de esta reserva. No marcar los
cuatro submódulos `CUBIERTO` integral con la presente entrega.

## Validación y reversión

`vendor/bin/phpunit --filter GestM15VerificationTest`;
`CI Factory v1 / Validar` + `Validar` exact-HEAD y controles de seguridad
de GitHub. Reversión por squash revert; no cambia tablas ni migraciones.
