# Condor App — Snapshot operativo · Rector gate V 0.1.47

> **Candidato:** Issue #245 · primer lote Rector acotado y dry-run obligatorio.

Condor permanece en **construcción**. V0.1.47 aplica el primer lote Rector con cambios mecánicos y verificables, y convierte el dry-run de Rector en gate del CI para el alcance configurado.

## Alcance
- lote Rector limitado a `CatalogSchemaListener.php` y `ContainerRecovery.php`;
- cambios producidos por Rector: tipado explícito `string` en cuatro constantes privadas;
- ningún cambio de firma pública, lógica de negocio, Sentry, Doctrine runtime o flujo de requests;
- `rector.php` conserva sets PHP/code-quality/dead-code/Symfony/Doctrine, pero con paths explícitamente acotados;
- job `Rector dry-run` integrado al check agregado `Validar`;
- contratos que fijan el allowlist, el estado post-Rector y la presencia del gate.

## Seguridad y reversión
- el workflow efímero que aplicó Rector validó allowlist de archivos, ejecutó dry-run posterior y PHPStan antes de fijar el commit; no forma parte del candidato final;
- no hay migraciones, SQL, secretos ni cambios de permisos productivos;
- revertir este corte restaura las constantes sin afectar datos ni contratos externos;
- futuras ampliaciones de Rector deben hacerse por lotes pequeños y revisables.

## Evidencia base
- `main@d14f8ca88873e717eec1c310be5a5d481543be3b` · V0.1.46 GREEN;
- workflow controlado #248 aplicó Rector real y el diff resultante tocó exactamente dos archivos allowlisted;
- dry-run posterior + PHPStan: SUCCESS;
- reserva v2 #245: `02fb736e-c1ab-4526-aef4-2ef0fdeeb731`.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Parent de calidad: #212
- Slice actual: #245
