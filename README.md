# Condor App — Snapshot operativo · PHPStan/Rector base V 0.1.46

> **Candidato:** Issue #244 · base reproducible de análisis estático PHP.

Condor permanece en **construcción**. V0.1.46 incorpora PHPStan nivel 8 con baseline versionada y prepara Rector de forma conservadora, sin aplicar todavía el refactor masivo ni convertir Rector en gate.

## Alcance
- PHPStan + extensiones Symfony/Doctrine y Rector fijados en Composer con lock regenerado sobre V0.1.45;
- baseline PHPStan regenerada con PHP 8.5 y verificada limpia;
- job `Análisis estático PHP` obligatorio dentro del check agregado `Validar`;
- Rector disponible para inspección local, sin gate bloqueante hasta #245;
- comandos locales documentados en AGENTES.md;
- contratos que impiden perder tooling, baseline o introducir Rector prematuramente como gate.

## Seguridad y reversión
- herramientas exclusivamente `require-dev`;
- no hay migraciones, SQL, secretos, cambios de permisos productivos ni refactors de aplicación;
- el workflow bootstrap que generó lock/baseline fue efímero y se elimina en el candidato final;
- revertir este corte elimina tooling/config/gate sin tocar datos ni runtime productivo.

## Evidencia base
- `main@4a2923139af9aff775312db827f0159ffc512b34` · V0.1.45 GREEN;
- bootstrap reproducible #246 generó lock + baseline con PHP 8.5;
- #245 queda bloqueado como segundo slice para aplicar un lote Rector acotado y solo entonces activar su dry-run en CI;
- reserva v2 #244: `ad1e7435-14a5-49e4-b76e-1a7c9a0d16b2`.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Parent de calidad: #212
- Slice actual: #244
