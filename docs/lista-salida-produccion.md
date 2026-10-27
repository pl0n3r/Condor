# Lista de salida a producción de Condor

**Corte:** 27-oct-2026 · fuente: este repositorio, sus workflows y los issues citados. Esta lista **no autoriza el go-live**; la decisión de salir en vivo, el gasto y los datos reales son solo del dueño.

## Reglas

- `LISTO`: con evidencia verificable en el repo. `EN_CURSO`: con issue ejecutable o PR abierto (nunca un epic o roadmap). `SIN_COBERTURA`: sin issue previo; la hoja nueva está enlazada. `DEL_DUEÑO`: requiere una decisión, cuenta o gasto del dueño. `NO_APLICA`: con razón.

## Inventario

| Área | Requisito | Estado | Evidencia | Issue |
| --- | --- | --- | --- | --- |
| Respaldos y restauración | Respaldo y restauración probada de la base de datos | `SIN_COBERTURA` | Existe un simulacro manual (`.github/workflows/recovery-production.yml`, `docs/recovery-production.md`) que depende de una conexión de ControlBot todavía apagada; no hay respaldo agendado ni restauración ensayada de Condor. | [#981](https://github.com/pl0n3r/Condor/issues/981) |
| Respaldos y restauración | Respaldo antes de migraciones en producción | `LISTO` | Decisión D-054 (auto-migración aditiva con backup) y `docs/LIVE-MIGRATION-POLICY.md`; 28 hojas cerradas citan D-054. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Monitoreo y alertas | Monitoreo externo de disponibilidad con alerta | `LISTO` | `.github/workflows/external-uptime.yml` (4 veces por hora) y `docs/LIVE-UPTIME-RUNBOOK.md`; última ejecución exitosa observada el 27-oct-2026. | [#613](https://github.com/pl0n3r/Condor/issues/613) |
| Monitoreo y alertas | Captura de errores en producción (Sentry) | `DEL_DUEÑO` | `docs/sentry-datos-minimos.md`: el contrato existe pero `SENTRY_DSN` no está activo; activar exige tu cuenta y la clave. | [#617](https://github.com/pl0n3r/Condor/issues/617) |
| Monitoreo y alertas | Estado del sistema visible para el propietario | `LISTO` | Panel «Estado del sistema» fusionado (#964, PR #967) y `SystemHealthPanel.tsx`. | [#964](https://github.com/pl0n3r/Condor/issues/964) |
| Dominios, DNS y TLS | Conectar un dominio real a un storefront | `DEL_DUEÑO` | `docs/storefront-dominios.md` describe la transición segura; el dominio, el DNS y el certificado dependen de tu cuenta de dominio. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Dominios, DNS y TLS | Dominio personalizado por empresa: propiedad, DNS, TLS y aislamiento | `DEL_DUEÑO` | `docs/storefront-dominios.md` documenta hostname por tenant, comprobación independiente de propiedad, DNS, certificado y aislamiento; activar un dominio real requiere autorización y acceso del dueño/empresa. No hay evidencia de dominio de cliente activo. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Secretos y accesos | Inventario de secretos, responsables y rotación | `SIN_COBERTURA` | No existe un inventario único de secretos y accesos de producción; solo se menciona `CONDOR_SMOKE_EMAIL` y `CONDOR_SMOKE_PASSWORD` en `docs/LIVE-AUTH-SMOKE-RUNBOOK.md`. | [#983](https://github.com/pl0n3r/Condor/issues/983) |
| Costos y límites | Presupuesto de infraestructura y alertas de gasto | `SIN_COBERTURA` | No hay documento de costo mensual de producción de Condor. | [#984](https://github.com/pl0n3r/Condor/issues/984) |
| Costos y límites | Aprobación de gasto del dueño | `DEL_DUEÑO` | Todo gasto real (hosting, correo, monitoreo, dominios) lo decides tú; está fuera del alcance de los chats. | [#984](https://github.com/pl0n3r/Condor/issues/984) |
| Privacidad y legal | Borradores base de aviso, política, términos y registro de tratamientos | `LISTO` | `docs/privacidad/` contiene borradores, canal de derechos, registro y retención; `datos.yml` y workflows de privacidad validan su estructura, NO su aprobación jurídica ni textos por empresa. | [#617](https://github.com/pl0n3r/Condor/issues/617) |
| Privacidad y legal | Aviso de privacidad y términos por empresa cliente | `DEL_DUEÑO` | Los borradores de `docs/privacidad/` y los responsables pendientes en `datos.yml` no acreditan avisos o contratos por empresa; identidad, textos finales y aprobación jurídica dependen de autoridad humana. | [#617](https://github.com/pl0n3r/Condor/issues/617) |
| Privacidad y legal | Revisión por abogado del paquete legal | `DEL_DUEÑO` | `revision-juridica/` está sincronizado, pero la aprobación profesional la hace una persona abogada contratada por ti. | [#617](https://github.com/pl0n3r/Condor/issues/617) |
| Privacidad y legal | Datos de RR. HH. y asistencia reales | `DEL_DUEÑO` | Decisión B de #742: RR. HH. apagado hasta que cambies esa decisión. | [#742](https://github.com/pl0n3r/Condor/issues/742) |
| Soporte y operación | Runbook de soporte y respuesta a incidentes | `SIN_COBERTURA` | Hay dominio de soporte (`docs/knowledge-support-*.md`) pero no un runbook operativo de atención y escalamiento. | [#985](https://github.com/pl0n3r/Condor/issues/985) |
| Soporte y operación | Canales, escalamiento y soporte a empresas clientes | `SIN_COBERTURA` | La hoja ejecutable #985 exige canales, horarios, niveles de severidad y responsables de soporte a empresas clientes; todavía no hay evidencia de operación de ese servicio. | [#985](https://github.com/pl0n3r/Condor/issues/985) |
| Migración de datos | Política de migraciones de esquema | `LISTO` | `docs/LIVE-MIGRATION-POLICY.md` y D-054, con pruebas. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Migración de datos | Importación de datos de la empresa piloto | `SIN_COBERTURA` | No hay plan ni ensayo de carga de catálogo, clientes e inventario reales. | [#986](https://github.com/pl0n3r/Condor/issues/986) |
| Migración de datos | Autorizar datos reales de la primera empresa piloto | `DEL_DUEÑO` | `datos.yml` declara `no_real_customer_data=true`. Importar información real requiere autorización expresa y revisión legal; #986 solo prepara plan y ensayo sintético. | [#986](https://github.com/pl0n3r/Condor/issues/986) |
| Reversión y apagado | Reversión de despliegue y apagado de emergencia | `SIN_COBERTURA` | No hay un runbook de reversión y apagado de emergencia para Condor o un tenant. | [#982](https://github.com/pl0n3r/Condor/issues/982) |
| Seguridad | Análisis estático y de dependencias en CI | `LISTO` | `.github/workflows/seguridad.yml`, SonarCloud y CodeQL se ejecutan en CI sobre cada PR. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Seguridad | Revalidación integral de calidad, seguridad y accesos | `EN_CURSO` | Hoja de readiness abierta; depende de la decisión de go-live del dueño. | [#616](https://github.com/pl0n3r/Condor/issues/616) |
| Accesibilidad y rendimiento | Presupuestos de rendimiento y accesibilidad verificados en CI | `SIN_COBERTURA` | D-041 define el sistema de diseño, pero no hay presupuesto medible de rendimiento ni accesibilidad verificado automáticamente. | [#987](https://github.com/pl0n3r/Condor/issues/987) |
| Smoke de producción | Smoke autenticado de solo lectura | `LISTO` | `.github/workflows/authenticated-production-smoke.yml` y `docs/LIVE-AUTH-SMOKE-RUNBOOK.md`; ejecutado con éxito en PRs recientes. | [#613](https://github.com/pl0n3r/Condor/issues/613) |
| Pagos y correo | Pasarela de pagos y proveedor de correo reales | `DEL_DUEÑO` | `datos.yml` mantiene `providers: []`; elegir proveedor, aceptar términos y pagar es decisión tuya. | [#1](https://github.com/pl0n3r/Condor/issues/1) |
| Facturación y planes | Facturación y planes comerciales para empresas clientes | `DEL_DUEÑO` | #639 acredita la vista técnica de planes, uso y entitlements, no facturación ni cobros reales. Tarifas, documentos contractuales, proveedores y obligaciones de facturación necesitan decisión del dueño antes de aceptar clientes. | [#639](https://github.com/pl0n3r/Condor/issues/639) |
| Salida en vivo | Decisión de go-live | `DEL_DUEÑO` | Solo el dueño decide salir en vivo; la lista previa de readiness (#389) está cerrada y #616 sigue abierta. | [#389](https://github.com/pl0n3r/Condor/issues/389) |

## Resumen

Total de filas: 27

- LISTO: 7
- EN_CURSO: 1
- SIN_COBERTURA: 8
- DEL_DUEÑO: 11
- NO_APLICA: 0

**Qué bloquea el go-live hoy:** ocho requisitos sin cobertura agrupados en siete hojas ejecutables (#981–#987), revalidación #616 y decisiones humanas pendientes: facturación y tarifas, gasto y proveedores, DNS/TLS por empresa, Sentry, textos y aprobación legal por empresa, autorización de datos de la primera empresa piloto y puerta de RR. HH. (decisión B). Ningún estado `LISTO` acredita go-live ni despliegue.
