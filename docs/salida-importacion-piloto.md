# Condor · plan de importación de la empresa piloto

**Estado:** preparación documental y ensayo **exclusivamente sintético** durante `construccion`. Este plan no autoriza importar datos reales, ejecutar en producción, modificar el esquema, contratar proveedores ni activar `live`. Referencias: [checklist de salida](lista-salida-produccion.md), [política de migraciones](LIVE-MIGRATION-POLICY.md), `datos.yml` (atestación D-063), D-054 y [Condor#986](https://github.com/pl0n3r/Condor/issues/986).

## Propósito y alcance

Preparar una importación por empresa (*tenant*) de **catálogo**, **clientes** e **inventario**, con conciliación de filas, aislamiento y reversión comprobable. Este issue entrega un **plan**, no un importador nuevo. El ensayo no se conecta a servicios ni a bases de datos operativas: los archivos del ensayo son inventados desde cero y no derivan de datos reales ni anonimizados. Ningún éxito de CI constituye una autorización para importar datos personales.

## Formatos de entrada y contrato mínimo

- Una entrega futura por empresa y conjunto de datos mediante CSV **UTF-8**, cabeceras explícitas, delimitador coma, comillas dobles para escapar valores y manifiesto versionado de lote, origen, conteos, esquema y SHA-256. Rechazar codificación inválida, cabeceras duplicadas y columnas desconocidas; nunca ejecutar fórmulas de celdas.
- **Catálogo:** `tenant_external_key`, `sku`, `nombre`, `unidad`, `precio_cop`, `activo`. SKU único por empresa, precio numérico no negativo, unidad registrada y booleanos estrictos.
- **Clientes:** `tenant_external_key`, `cliente_ref`, `nombre`, `email`, `telefono`, `autorizacion_tratamiento_ref`. Identificador único por empresa, minimización y base jurídica demostrable. Nombre, correo y teléfono son datos personales: no incluir valores en logs, fixtures, evidencia ni repositorio. Una referencia declarada no demuestra consentimiento válido.
- **Inventario:** `tenant_external_key`, `sku`, `ubicacion_ref`, `cantidad`, `unidad`. Validar SKU/ubicación del mismo tenant, números finitos no negativos, precisión y unidad compatibles. Establecer si es fotografía inicial o movimiento; nunca sumar dos veces una fotografía.
- Ensayo únicamente con identificadores inventados `TENANT_FICTICIO`, `CLI_SINTETICO_001`, `SKU_SINTETICO_001`, sin personas, correos, documentos, teléfonos ni dominios reales. La pertenencia al tenant se determina en el servidor, no por el CSV.

## Responsables y límites de autoridad

| Responsable | Trabajo | Puerta |
| --- | --- | --- |
| Dueño de Condor | Seleccionar empresa piloto, propósito, lotes, ventana, acceso, presupuesto y autorización de ejecución | **DECISIÓN DEL DUEÑO: pendiente** |
| Empresa piloto y responsable del tratamiento | Procedencia, aviso, base de tratamiento, retención, titulares, contratos y derechos | **DECISIÓN/ACUERDO HUMANO: pendiente** |
| Ingeniería/DBA | Mapeo de entidades, transacción, validación, claves idempotentes y aislamiento entre empresas | Solo preparación sintética |
| QA/seguridad | Casos negativos, permisos, conteos, fuga de PII, ensayo de restore y evidencia reproducible | Entorno local descartable |
| Operaciones/SRE | Diseñar backup, ventana, monitoreo, abortos y restauración futura | **Autorización del dueño antes de acciones live** |

**Cuentas, credenciales, acceso a Hostinger, proveedores, contratos, dominios, DNS y gasto:** exclusivamente **DECISIÓN DEL DUEÑO**, pendientes. Nunca publicarlos ni solicitarlos en un Issue público. Cambiar finalidad, categorías de datos o un proveedor requiere cambio posterior de `datos.yml` y puerta legal adecuada. La atestación D-063 `no_real_customer_data=true` y la fase `construccion` no permiten importar datos reales.

## Procedimiento y verificaciones

1. **Definición:** el dueño y la empresa acuerdan orígenes, responsable, finalidad, base legal, volumen, retención, permisos, ventana y criterios de éxito. **No recibir ni almacenar archivos reales en GitHub**. Sin contrato/autorización o revisión legal aplicables, detener aquí.
2. **Mapeo:** ingeniería documenta correspondencia de columnas y tipos con entidades existentes, valores nulos, categorías permitidas, claves únicas y vínculos tenant. Secuencia propuesta **catálogo → clientes → inventario**; cualquier orden distinto exige verificar dependencias antes. No hacer cambios de esquema ni código en esta hoja.
3. **Ensayo sintético:** generar CSV inventados en almacenamiento local descartable; cubrir lote vacío, codificación inválida, claves repetidas, SKU ausente, cantidad negativa, pertenencia a otro tenant, carga parcial simulada, lote repetido y diferencias de inventario. No extraer muestras reales, ni siquiera enmascaradas.
4. **Preflight y dry-run:** validar estructura CSV, manifiesto, checksum, autorización de actor/tenant por separado del archivo, tipos, duplicados, claves foráneas, conteos esperados y privacidad sin escribir persistencia. Ante cualquier anomalía **abortar** el lote entero sin importación parcial silenciosa. Informes solo de agregados y códigos de error, nunca valores de cliente.
5. **Carga simulada:** únicamente sobre base **descartable** sin datos reales, ensayar transacción por lote y claves `(tenant, external_key)`, `(tenant, sku)`; ligar `batch_id` a checksum para distinguir reintento idempotente de carga conflictiva. Verificar que referencia o importación cross-tenant falla, repetir lote sin duplicar registros y conciliar esperados/insertados/actualizados/rechazados.
6. **Reversión:** tomar snapshot de datos **sintéticos** previo al ensayo; inyectar error a mitad del lote, comprobar `ROLLBACK`, restaurar sobre base descartable, comparar conteos, relaciones y checksum antes/después. Para datos reales D-054 exige migraciones aditivas, dry-run/allowlist y backup verificable. No ejecutar borrado, truncado, SQL destructivo ni restore productivo en este issue.
7. **Evidencia:** QA archiva solo commit SHA, versión del contrato, hashes de fixtures ficticios, conteos agregados, pruebas negativas, rechazo cross-tenant, idempotencia, rollback y resultado de restore. Ingeniería y seguridad revisan; eliminar archivos temporales. Ningún CSV de clientes, dump, token, ruta privada o identificador real se comparte públicamente.
8. **Puerta de ejecución real (NO habilitada):** antes de cualquier importación real se requiere **DECISIÓN EXPLÍCITA DEL DUEÑO** para empresa, privacidad/revisión legal, cuentas, gasto, backup/restore, ventana y cambio operativo; nueva tarea autorizada y evidencia terminal. No implica activar `live` ni autoriza go-live.

## Evidencia requerida, criterios de parada y reversión

- **Evidencia sintética reproducible:** manifiesto, SHA-256 de fixtures ficticios, versión, conteos de catálogo/clientes/inventario, validaciones, lotes duplicados, abortos, aislamiento tenant, prueba de restauración y ausencia de PII/secretos. Responsable QA; conservar reportes sin valores de contacto.
- **Pendientes antes de operación futura real:** aprobación de empresa/contratos y revisión legal, documentación de base jurídica y retención en canal privado, backup receipt verificable, permisos, ventana, checklist de recuperación, autorizaciones del dueño. Nada de esta evidencia existe por inferencia.
- **Abortar** por tenant ambiguo, esquema o checksum inválido, credencial/base legal desconocida, lote conflictivo, integridad referencial fallida, discrepancia de inventario, ausencia de backup o propuesta destructiva. No continuar parcialmente ni declarar GREEN sin observación.
- **Reversión de este issue:** revertir el squash que agrega documento y prueba; no se generan registros de negocio. **Reversión de futura carga:** definición y aprobación separadas; un restore podría destruir escrituras posteriores y nunca es automático.

**Pendiente exclusivamente del dueño:** decisión de piloto y calendario; origen y autorización de datos reales; responsables y revisión legal; selección de cuentas/proveedores y cualquier gasto; aprobación de ventana/backup/restore productivos; ejecución real y go-live. **Sin respuesta, la decisión segura es NO importar y NO activar live.**
