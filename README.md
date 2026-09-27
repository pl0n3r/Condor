# Condor App — Snapshot operativo · Commercial Catalog Activation V 0.1.56

> **Candidato:** Issue #275 · activación productiva idempotente del catálogo comercial.

Condor continúa en **construcción**. V0.1.56 convierte el catálogo persistente de V0.1.55 en una capacidad operable: el seed canónico se ejecuta de forma transaccional e idempotente mediante comando Symfony y el post-deploy lo activa después de reconciliar schema, antes de declarar el deploy completo.

## Alcance
- `app:commercial:seed` como comando Symfony sin duplicar definiciones comerciales;
- `CommercialCatalogSeeder::seed()` ejecutado dentro de `EntityManager#wrapInTransaction`;
- activación automática únicamente en etapa `construction`;
- orden productivo: schema check → D-054 migrate/recheck si aplica → catalog seed → cache → complete;
- fallo de seed bloquea cache/complete y deja evidencia `phase=catalog-seed/result=failure`;
- `live` conserva el contrato sin escritura automática del catálogo;
- aceptación Factory en `tests/test_commercial_catalog_activation.py`.

## Seguridad, datos y reversión
El comando materializa solo el catálogo comercial aprobado en #265; no procesa PII ni secretos y `datos.yml` no cambia. La transacción evita estados parciales. El post-deploy sigue protegido por lock y D-054 para schema. Revertir código desactiva la automatización; no se borra catálogo productivo automáticamente.

## Evidencia base
- `main@f78ab084944421e3b9a2736642f02230853ac0e5` · V0.1.55 · PRODUCCIÓN EN VERDE.
- Reserva #275: `dcfe035b-7078-431e-a93e-fd0cacbce1b9`.
- #269 permanece abierto hasta integrar esta activación y verificar 4 planes en producción.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- Roadmap: Issue #1
- Épico comercial: #265
- Commercial Catalog parent: #266
- Activación actual: #275
