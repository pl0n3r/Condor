# F1a — Matriz normativa sintética por tenant (offline)

El contrato `NormativeMatrix::evaluate(rules, context, referenceDate)` **no certifica cumplimiento**. La decisión A de #836 autoriza una proyección informática de F1a, no un catálogo oficial, dictamen jurídico ni activación. La implementación permanece **sin activación operativa** hasta una revisión profesional independiente y otra autorización.

## Entradas y aislamiento

La matriz recibe una lista de reglas inventadas con el esquema exacto de `NormativeApplicability` (F0 #806), un perfil ficticio y una fecha ISO. Usa solo PHP puro; no lee fuentes externas. Cada regla válida lleva `tenant_ref` y coincide con el perfil solicitado. Las reglas de **otros tenants se omiten sin exponer identidad ni procedencia**; reglas sin ámbito, estructuras anómalas o contextos incompletos se proyectan como `REQUIERE_REVISION` sin fuente acreditada. Se limita a 100 reglas y se rechazan entradas no listadas.

Las dimensiones `sector`, `ciiu`, `municipio` (jurisdicción local), `riesgo`, `tamano_empresa` y `regimen` son etiquetas sintéticas, no clasificación jurídica colombiana validada. Una condición territorial distinta pero conocida, con fuente y revisión válidas, produce `NO_APLICA`; un municipio ausente/desconocido nunca permite decidir inaplicabilidad. Fechas futuras, vencidas o inválidas dan `REQUIERE_REVISION`.

## Salida y procedencia

Cada fila conserva `kind`, `applicability` y `explanation` del evaluador F0. Para reglas completas, revisadas y vigentes conserva `source` (emisor, artículo, versión, URL HTTPS declarada), `reviewer_ref`, `verified_on`, `effective_from` y `effective_until`. Esta procedencia es una **declaración del fixture**, no validación de oficialidad: `official_url` usa `example.invalid` y `approved=true` es solo una entrada sintética del test. Sin certeza, los metadatos se redactan como `null` y se exige revisión.

La clasificación `OBLIGATORIO`, `CONDICIONAL`, `PROHIBIDO` o `RECOMENDADO` expresa únicamente la etiqueta de una regla ficticia. `NO_APLICA` solo surge ante discrepancia explícita y conocida del mismo tenant. El resultado **no certifica cumplimiento** de ninguna empresa ni evalúa pruebas jurídicas reales.

## Límites y reversión

- **Sin datos personales**, empleados, historias clínicas, credenciales, proveedores ni comunicaciones reales.
- **Sin BD, API ni UI**; tampoco SQL, migraciones, persistencia, HTTP, red, jobs, cron, consola de producción ni terceros.
- **Sin activación operativa**, publicación normativa, atención a clientes, RRHH, contratos, SG-SST, nómina o asesoría legal sin **revisión profesional**.
- **No deploy**, release, go-live ni alteración de D-043, D-059, D-063 ni de la opción B de RRHH en #742.
- Tests offline: `python3 -m unittest tests.test_compliance_normative_matrix`, que invoca PHP real de F0/F1a.
- Reversión: revertir solo los tres archivos de #837. No existen efectos externos ni datos persistidos.
