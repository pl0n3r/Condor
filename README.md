# Condor App — Snapshot operativo · Plan Configurator D V 0.1.62

> **Candidato:** Issue #290 · consumidores comerciales canónicos.

Condor continúa en construcción. V0.1.62 conecta las superficies comerciales visibles con una sola fuente: `CommercialCatalogReader`. La página pública de precios, el configurador y el Control Center comparten la identidad/versiones del catálogo vigente sin duplicar importes ni reglas en Twig o React.

## Alcance
- nueva superficie SSR pública `/precios` alimentada por el catálogo comercial canónico;
- navegación pública desde inicio y configurador hacia precios;
- `/adminpl0n3r/api/context` expone `commercial_catalog` desde el mismo reader;
- Control Center presenta planes vigentes en modo solo lectura, sin convertirse en fuente de pricing;
- `/configurar-condor` conserva sus APIs canónicas de catálogo, opciones y quote;
- identidad `plan key + PlanVersion` compartida entre `/precios`, configurador y platform owner;
- Enterprise/proposal permanece sin importe inventado en las tres superficies;
- regresiones estáticas contra precios/reglas duplicados y WebTestCase runtime con catálogo seed real;
- versión candidata `0.1.62`.

## Seguridad y reversión
No se modifican PlanVersion, precios, compatibilidades, migraciones ni datos persistentes del catálogo. El frontend únicamente presenta datos server-side. Enterprise sigue requiriendo propuesta y no recibe un total sintético. Reversión: revert del slice restaura las superficies anteriores sin migración ni restauración de datos.

## Evidencia base
- `main@45e54912107e8b4c080072d6ce405db5f9ebda15` · V0.1.61 como base del candidato.
- Reserva #290: `8722e296-a73a-49f7-a6fe-f0110c5feeee`.
- PR #293 valida AC-01..AC-04 para el cierre de consumidores de #280B.
- #280 permanece como parent hasta integrar también la telemetría privacy-safe restante.
