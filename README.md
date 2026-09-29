# Condor App — Snapshot operativo · Release identity hotfix V 0.1.76

> **Candidato objetivo:** V0.1.76 · Issue #330 · recuperar la identidad humana del release después de #328.
>
> **Producción observada antes del hotfix:** `main@51761550c2f67a53156cf334c6a1d906c033c82b` responde `status=ok`, `version=0.1.72`, `release_sha` exacto y `schema_up_to_date=true`. El Deploy Observer reintentado pasó sobre ese SHA, pero Release Factory v1 rechazó reutilizar `v0.1.72`, que ya pertenece a `5367b55ffe1b70fbda035854480fb0ba7fd48f7f`.

Condor continúa en construcción. V0.1.76 corrige únicamente la identidad de release del `main` ya integrado por #328; no modifica comportamiento funcional.

## Alcance
- actualizar `config/version.php` a `0.1.76`;
- mantener README y regresiones de identidad alineados con el candidato;
- preservar sin cambios Market, Knowledge, Password Recovery, dependencias, esquema, SMTP, secretos, DNS y datos;
- conservar intacto el tag histórico `v0.1.72`.

## Seguridad y datos
Este hotfix no agrega datos personales, proveedores, formularios ni tratamientos; no modifica `datos.yml`. Tampoco ejecuta migraciones, cambios destructivos ni mutaciones de producción.

## Evidencia base
- `main@51761550c2f67a53156cf334c6a1d906c033c82b`: CI exact-main success; `/health` sirve el SHA exacto y esquema al día.
- Deploy Observer run `36464165903`: intento 2 **success** tras recuperación de la respuesta HTTP.
- Release Factory v1 run `36464166935`: **failure** en `Asegurar tag anotado` porque `v0.1.72` ya apunta a otro commit.
- El cambio integrado #328 declara **V 0.1.76** y `v0.1.76` todavía no existe.
- Decisión D-056: releases mediante Factory, validados por versión y SHA exactos.
