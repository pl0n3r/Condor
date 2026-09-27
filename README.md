# Condor App — Snapshot operativo · Release identity hotfix V 0.1.66

> **Candidato objetivo:** V0.1.66 · Issue #307 · corregir la identidad humana del release después de #306.
>
> **Producción actual:** `main@6f32f2459e906ae6eb5a5dea639e96a4316e7701` sirve `status=ok`, `version=0.1.65` y `schema_up_to_date=true`; el observer exact-main pasó. El release falló porque `v0.1.65` ya pertenece al SHA anterior.

Condor continúa en construcción. V0.1.66 es un hotfix de identidad de release: cambia la fuente canónica de versión sin modificar lógica funcional, dependencias, esquema ni configuración de correo.

## Alcance
- actualizar `config/version.php` a `0.1.66`;
- mantener README alineado con el candidato y la evidencia productiva real;
- preservar sin cambios Symfony Mailer, `composer.lock`, esquema, SMTP, secretos, DNS y datos.

## Seguridad y datos
Este hotfix no agrega datos personales, proveedores, formularios ni tratamientos; no modifica `datos.yml`. Tampoco ejecuta migraciones ni cambios destructivos.

## Evidencia base
- `main@6f32f2459e906ae6eb5a5dea639e96a4316e7701`: CI exact-main y observer en success; `/health` sirve el SHA exacto.
- Release Factory v1 run `36357653170`: failure en `Asegurar tag anotado` con `v0.1.65 ya apunta a otro commit`.
- Decisión D-056: releases mediante Factory, validados por versión y SHA exactos.
