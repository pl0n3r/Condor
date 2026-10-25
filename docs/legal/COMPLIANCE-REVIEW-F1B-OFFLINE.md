# F1b: revisión normativa offline, tenant-scoped

**Origen:** épico Condor #802, dirección del propietario #840 opción A y journal bot v2, hoja #916. Se ejecuta únicamente después del contrato F0 `NormativeApplicability` y F1a `NormativeMatrix` ya fusionados. **No certifica cumplimiento** ni constituye interpretación jurídica aprobada.

## Contrato y estados

`NormativeReviewProjection::evaluate(rules, context, date, reviews)` vuelve a evaluar F0/F1a antes de proyectar revisiones: no acepta una matriz fabricada desde otro servicio. Usa solo normas y perfiles **sintéticos** sin datos personales. Conserva `tenant_ref`, municipio, versiones y fechas validadas por F1a para el ámbito requerido.

- `REQUIERE_REVISION`: fuente, condición, vigencia, ámbito o recibo de revisión no verificables, ambigüedad o entradas desconocidas. Resultado fail-closed sin contenido de evidencia.
- `PENDIENTE`: regla F1a válida y aplicable, sin referencia de revisión aún; no equivale a incumplimiento ni cumplimiento.
- `EN_REVISION`: la referencia opaca `fixture:evidence/...` y el revisor sintético satisfacen los requisitos de forma y fechas; **no significa revisión humana real**.
- `NO_APLICA`: solo si el motor F1a acredita por condiciones explícitas una no aplicabilidad en el mismo tenant y municipio.
- **Nunca** se emite `CUMPLE`, ni se confiere permiso operativo por enviar un adjunto o estado.

Una revisión entrante lleva exclusivamente `tenant_ref`, `municipio`, `row_index`, `state=EN_REVISION`, `evidence_ref`, `reviewer_ref` y `reviewed_on`. Solo se ignoran sin revelar los recibos con **otro tenant o municipio explícito y verificable**. Una entrada sin ámbito comprobable (incluyendo un valor no estructurado) o con índice de fila ausente, negativo, no entero o fuera de rango en el ámbito propio genera **`REQUIERE_REVISION` para todas las reglas aplicables que podrían verse afectadas**, aun cuando exista una referencia válida adicional. No se convierte silenciosamente en `PENDIENTE`. Duplicados y otros errores del mismo ámbito también fallan cerrado. Referencias opacas solo apuntan a fixtures; **no almacenar ni cargar el contenido** de documentos. Fechas posteriores al corte, anteriores a revisión de fuente o fuera de vigencia no son válidas.

## Amenazas y límites

- **Sin datos personales:** ninguna identidad de trabajador, cliente ni expediente; los identificadores tenant y revisor son alias sintéticos locales.
- **Sin BD, API ni UI:** clase PHP pura sin SQL, Doctrine, HTTP, consola de operaciones, escritura de archivos ni estado persistente.
- **Sin proveedores:** sin conexiones a terceros, OAuth, secretos, tokens, analítica ni pagos.
- **Sin activación operativa:** sin cambios en producción, permisos, migraciones, Hostinger o procesos RRHH.
- **Revisión profesional:** las obligaciones, fuentes oficiales, interpretaciones, retención documental y su vigencia requieren verificación jurídica auténtica antes de cualquier uso operativo. No habilita la opción jurídica B de Condor #742.
- **No deploy:** la implementación de #916 constituye solo preparación de pruebas; D-043 `VALIDATED_IN_PRODUCTION`, CI y autoridades de release siguen siendo puertas independientes. Nada de este contrato prueba que producción esté lista.

## Pruebas y reversión

`python3 -m unittest tests.test_compliance_normative_review_projection -v` ejecuta los cuatro criterios originales de F1b más tres regresiones de #921 mediante el **PHP real**, con referencias y contextos inventados. Las pruebas incluyen fuentes inválidas, límites tenant/municipio, fechas, replays deterministas, inexistencia de `CUMPLE` y ausencia de efectos de proveedor. El cambio se revierte eliminando únicamente los tres archivos nuevos de #916: no hay esquema, configuración ni datos que revertir.
