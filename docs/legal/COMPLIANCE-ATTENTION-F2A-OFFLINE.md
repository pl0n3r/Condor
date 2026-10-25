# F2a · Candidatos de atención normativa sintéticos (offline)

**Origen:** Condor #802, autorización de dirección #918/A, hoja #919. Esta entrega aplica solo a datos ficticios y no certifica cumplimiento. Es una proyección de candidatos internos para revisión profesional, nunca un dictamen jurídico.

## Semántica conservadora y procedencia

`ComplianceAttentionProjection::project($f1bProjection, $deadlines, $referenceDate)` toma la salida sintética F1b, valida `tenant_ref` y `municipio` y devuelve únicamente `rows` de revisión. Las filas contienen `state`, `deadline_ref`, `due_on` y `attention_candidate` (booleano), sin adjuntos ni referencias de clientes.

Los plazos son fixtures con **exactamente** `tenant_ref`, `municipio`, `row_index`, `deadline_ref=fixture:deadline/...`, `due_on`, `source_version` y `verified_on`. La fuente F1b debe traer los cinco atributos canónicos `article`, `issuer`, `kind=official`, `official_url` y `version`: son identificadores ficticios completos, dominio HTTPS reservado `.invalid`, nunca referencias externas ni prueba de autoridad legal. La versión de fuente debe coincidir con la de F1b. Se revisan fechas UTC, vigencia de fuente, relación fecha/plazo y la evidencia opaca de revisión. Fuentes, fechas, índices o ámbitos ambiguos → `REQUIERE_REVISION` sin plazo ni candidato. Duplicados del mismo índice tampoco producen alertas.

- **`PENDIENTE` heredado de F1b siempre → `REQUIERE_REVISION`.** Los errores de F1b documentados en #802 pueden convertir una revisión malformada descartada en `PENDIENTE`; la proyección F2a no puede distinguirlo de una ausencia legítima de revisión. Por tanto falla cerrado y no implica que no haya riesgos.
- `EN_REVISION` solamente si el registro F1b y el plazo ficticio tienen procedencia y fechas coherentes. `attention_candidate=true` solo cuando `due_on` ya alcanzó `referenceDate`. Es un candidato a atención humana, **no una notificación enviada**.
- `NO_APLICA` es únicamente un estado sintético recibido de F1b con metadatos revisables, no equivale a inaplicabilidad normativa aprobada por un profesional.
- No emite `CUMPLE`, `VALIDADO`, cierre de auditoría ni puntajes de riesgo legal. `REQUIERE_REVISION` tampoco determina incumplimiento.

## Aislamiento y pruebas

**Sin datos personales**: usar `tenant-a`, `pereira`, revisores y fuentes inventadas de dominio `.invalid`; no incorporar RRHH, datos de trabajadores, documentos, historial médico ni PII de clientes. Las referencias **explícitamente ajenas y con tenant/municipio válidos** se ignoran sin revelar su existencia; un plazo sin ámbito verificable (incluidos tenant/municipio desconocidos, campos ausentes o entrada no estructurada) invalida conservadoramente la proyección completa, incluso si otro plazo es correcto. Una entrada malformada del mismo ámbito también la invalida. **Sin BD, API ni UI**, sin proveedores, sin SQL, sin red, sin scheduler y sin escrituras persistentes. El código PHP es puro en memoria.

`python3 -m unittest tests.test_compliance_attention_projection -v` ejecuta los cuatro criterios contra el **PHP real** con `php -n` y `allow_url_fopen=0`. La suite verifica la incertidumbre de F1b, procedencia, límites tenant/municipio, fechas inválidas, duplicados y ausencia de efectos no autorizados. No sustituir los tests por búsqueda de textos o mocks del algoritmo.

## Autoridad, reversión y límites operativos

El uso con normas reales exige revisión profesional y verificación de fuente oficial, aplicabilidad, vigencia, interpretación, privacidad y derechos antes de cualquier decisión. No se realizan afirmaciones de legalidad colombiana; no se permite activación con datos reales. La puerta D-043 requiere `VALIDATED_IN_PRODUCTION` sobre el SHA/version exactos antes de cualquier merge/deploy; CI verde no acredita el observador ni ese estado. **Sin activación operativa; no deploy** durante build-ahead. Solo PR DRAFT y validaciones del HEAD definitivo; CodeRabbit únicamente revisión FINAL tras CI verde.

Revertir suprimiendo los tres archivos nuevos de #919 mediante PR controlado. No existe esquema, migración, configuración, ruta HTTP ni tarea que deshacer; se preserva la opción jurídica B de #742.
