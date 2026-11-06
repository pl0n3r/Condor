# GEST-M05 · Seguridad social — verificación sintética de seis submódulos (#1530)

**PR de verificación:** [#1708](https://github.com/pl0n3r/Condor/pull/1708), entrega candidata; no se considera fusionado hasta recibir el comprobante de GitHub.

**Corte de código:** Condor `main@0a424dd07b54de9b83b8639892e604cd749d7b61`, 6 de noviembre de 2026. **Alcance:** construcción con identidades exclusivamente `fixture:`; la decisión B de [#742](https://github.com/pl0n3r/Condor/issues/742) continúa prohibiendo activar RR. HH. para datos reales. Ninguna prueba sustituye dictamen jurídico, afiliación real, cotización o presentación ante PILA.

## Inventario verificable y brecha residual

Se incorporan pruebas PHPUnit de comportamiento **PHP real**, `tests/php/Application/SocialSecurity/GestM05VerificationTest.php`, por cada ID. La atribución a este PR será válida solo después de que GitHub confirme su merge; hasta entonces todos los resultados son verificaciones de código candidatas. Los testigos previos se encuentran en PRs ya **fusionados** [#1101](https://github.com/pl0n3r/Condor/pull/1101), [#1102](https://github.com/pl0n3r/Condor/pull/1102), [#1158](https://github.com/pl0n3r/Condor/pull/1158) y [#1163](https://github.com/pl0n3r/Condor/pull/1163).

| ID | Submódulo | Código real, nuevo test exacto | Resultado y brecha para cobertura integral |
| --- | --- | --- | --- |
| `GEST-M05-S01` | Afiliaciones | `SocialSecurityContributionsProjection::project` · `testS01AfiliacionesSinteticasNoAutorizanTramites`; `SocialSecurityApiService` + `SocialSecurityController` en #1163 | **Parcial / NO_VERIFICABLE**: existe API ficticia de afiliaciones con scope y RBAC; no acredita afiliación operativa, persistencia habilitada ni pantalla completa |
| `GEST-M05-S02` | Novedades | `SocialSecurityContributionsProjection::project` · `testS02NovedadesReferencianAfiliacionDelMismoTenant` | **Parcial / NO_VERIFICABLE**: novedades son referencias locales, sin CRUD HTTP de novedades ni trazabilidad de actualización efectiva |
| `GEST-M05-S03` | Aportes | `SocialSecurityContributionsProjection::project` · `testS03AportesSonCalculoIlustrativoVersionado` | **Parcial / NO_VERIFICABLE**: tasas ficticias versionadas, no liquidación reglamentaria, API o pago real |
| `GEST-M05-S04` | Soportes | `SocialSecurityPilaPreparation::project` · `testS04SoportesRechazanDuplicadosYSuprimenTenantAjeno` | **Parcial / NO_VERIFICABLE**: referencias ficticias filtradas; faltan repositorio documental, ACL y HTTP propio |
| `GEST-M05-S05` | Alertas | `SocialSecurityPilaPreparation::project` · `testS05AlertasSoloIdentificanCandidatasDeAtencion` | **Parcial / NO_VERIFICABLE**: solo fechas candidatas; sin notificación externa, UI asíncrona ni alertas verificadas |
| `GEST-M05-S06` | Preparación PILA | `SocialSecurityPilaPreparation::project` · `testS06PilaEsBorradorNoEnviable` | **Parcial / NO_VERIFICABLE**: `BORRADOR_NO_ENVIABLE` / `can_send=false`, sin generación legal, operador o transmisión |

**0 promociones a `CUBIERTO` integral**: se conservan las seis filas `NO_VERIFICABLE` en `docs/matriz-cobertura.md`, que este Issue no reclama. La matriz se concilia únicamente desde su hoja propietaria.

## Controles HTTP, permisos y frontend

`SocialSecurityController` ofrece únicamente cuatro operaciones sintéticas de **afiliaciones**: listar, ver, registrar y revisar. Exige OWNER, `authorizedBranchScope`, permisos `users.view/create/update` y CSRF en mutaciones. Su `secureScope` deshabilita la API fuera de test/dev con bandera `CONDOR_SOCIAL_SECURITY_SYNTHETIC_API=1` y fase `construccion`. `testContratoHttpExigePermisosCsrfYBanderaApagada` asegura estas guardas **estáticamente**; el test ejecutable preexistente `tests/test_social_security_http_api.py` ejercita el servicio PHP con ledger ficticio, scope y denegaciones, **no** un servidor HTTP autenticado real.

`frontend/admin/SocialSecurityManagement.tsx` es `DEV` y memoria local exclusivamente: filtra por tenant/empresa/sede y permiso, muestra vacío y denegación, pero carece de flujos `loading/error` de una API y de pruebas E2E integrales para los seis submódulos. Las tareas [#1656](https://github.com/pl0n3r/Condor/issues/1656) (backend con bandera OFF) y [#1657](https://github.com/pl0n3r/Condor/issues/1657) (UI, dependiente de #1656) conservan esos límites. La hoja hija [#1707](https://github.com/pl0n3r/Condor/issues/1707), bloqueada hasta cerrar #1530/#1656/#1657, concentra exclusivamente los contratos HTTP y estados UI que siguen faltando para S02–S06, sin repetir esas implementaciones. Ninguna tarea habilita datos personales o go-live por sí sola.

## Pruebas, permisos y reversión

Suite: `vendor/bin/simple-phpunit tests/php/Application/SocialSecurity/GestM05VerificationTest.php`. Pruebas negativas de tenant cruzado, novedades huérfanas, soporte duplicado, tasas fuera de rango, fechas inválidas y referencias de personas no ficticias. **Los estados de la UI y la autenticación HTTP completa siguen pendientes**: no se presentan aserciones estáticas como E2E. Gate exigido: `CI Factory v1 / Validar` terminal `success` sobre el SHA definitivo, más calidad CodeQL, SonarCloud y única revisión FINAL de CodeRabbit cuando CI esté verde.

Sin modificar controlador, entidades, frontend, rutas productivas, almacenamiento, bandera de producción, `datos.yml`, despliegue, secretos ni datos reales. Revertir el squash elimina solo la prueba y el informe.
