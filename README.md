# Condor App — Snapshot operativo · Release identity hotfix V 0.1.67

> **Candidato objetivo:** V0.1.67 · Issue #315 · corregir la identidad humana del release después de #314.
>
> **Producción observada antes del hotfix:** `main@de99cf65207c50aa2c6be7141ed7d4850e641d7b` sirve `status=ok`, `version=0.1.66` y `schema_up_to_date=true`. `main@93a2db4768756f06fb3d903e7b35a7ca1f4eeb44` ya integró #314, pero Release Factory rechazó reutilizar `v0.1.66`.

Condor continúa en construcción. V0.1.67 corrige únicamente la identidad de release posterior al dominio Knowledge de #314; no modifica comportamiento funcional.

## Alcance
- actualizar `config/version.php` a `0.1.67`;
- mantener README y contratos versionados alineados con el candidato;
- preservar sin cambios Knowledge, Symfony Mailer, `composer.lock`, esquema, SMTP, secretos, DNS y datos.

## Seguridad y datos
Este hotfix no agrega datos personales, proveedores, formularios ni tratamientos; no modifica `datos.yml`. Tampoco ejecuta migraciones ni cambios destructivos.

## Evidencia base
- `main@93a2db4768756f06fb3d903e7b35a7ca1f4eeb44`: PR #314 integrado con CI/privacidad/política verdes.
- Release Factory v1 run `36377482423`: failure en `Asegurar tag anotado` con `v0.1.66 ya apunta a otro commit`.
- Decisión D-056: releases mediante Factory, validados por versión y SHA exactos.
