# Condor · GEST-M12-S04 · riesgos tecnológicos y reputacionales (#1537)

Corte: 2026-11-05. Fase `construccion`. Solo referencias `fixture:` y sesión temporal;
ninguna evaluación profesional, dato real, aprobación de riesgos ni go-live.
`docs/matriz-cobertura.md` pertenece exclusivamente a la hoja de consolidación.

| Submódulo | Código y aserción exacta | Estado real |
| --- | --- | --- |
| GEST-M12-S04 · tecnológicos y reputacionales | `RiskApiService`, `RiskRegisterControls`, `RiskRecord`, `RiskController`; `tests/php/Application/Risk/GestM12VerificationTest.php` | **PARCIAL**: escenarios `tecnologico` y `reputacional` ficticios, con riesgo/revisión pendiente y sin dictamen, cierre o certificación |

Para reputación, el `POST /api/v1/branches/{branchId}/risks` requiere
`category=reputacional` y `scenario_ref=fixture:risk-scenario/<identificador>`
además de `risk_ref`, `probability` e `impact`. El formato anterior
(de cuatro campos) sigue rechazando `reputacional`, como comprueba
`tests/test_cobertura_condor_22.py`; evita falsificar una cobertura
heredada mientras permanece parcial la implementación GRC. No se aceptan
otros campos, PII ni transiciones `APROBADO` o `CERRADO`.

El HTTP existente usa `authorizedBranchScope`, permisos
`settings.view/create/update`, CSRF para escrituras, hash de
tenant+sede, auditoría y gate `test` / `dev` con
`CONDOR_RISK_SYNTHETIC_API=1` y `CONDOR_PRODUCTION_STAGE=construccion`.
Las pruebas PHP ejecutan las reglas reales y comprueban tenant/sede
ajenos, entradas no ficticias, tampering y ausencia de aprobación.

**Brecha pendiente:** `frontend/admin/RiskManagement.tsx` no acredita
el recorrido HTTP real ni sus cuatro estados `loading`, `empty`,
`error` y `permission-denied` con peticiones autenticadas. Esta
reserva no reclama `frontend/admin` ni `tests/e2e`; se deja para
la hoja hija [#1583](https://github.com/pl0n3r/Condor/issues/1583),
planificada y dependiente de #1537. Tampoco se acredita gestión GRC
productiva o evaluación reputacional certificada.

Validación: `vendor/bin/phpunit --filter GestM12VerificationTest`,
`tests/test_risk_http_api.py`, `tests/test_m12_riesgos.py` y
`tests/test_cobertura_condor_22.py`, más `CI Factory v1 / Validar`
sobre el HEAD exacto del PR. Reversión por squash revert; sin migraciones.
