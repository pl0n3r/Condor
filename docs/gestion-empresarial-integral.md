# Gestión empresarial integral — catálogo y contrato de evolución

**Propósito.** Este documento ordena los 16 módulos solicitados para usar dentro de Condor. Es una especificación de producto y desarrollo vinculada al [roadmap canónico #1](https://github.com/pl0n3r/Condor/issues/1) y al [épico legal #802](https://github.com/pl0n3r/Condor/issues/802); no es un segundo roadmap, una certificación legal ni una autorización de activar datos reales.

**Estado al 21 de octubre de 2026.** Condor está en `construccion`. Las capacidades comerciales ya presentes en código se describen en [Módulos operativos](modulos-operativos.md). Este documento no las declara desplegadas, contratables ni aprobadas para producción. Cualquier cambio ejecutable se descompone posteriormente en una hoja pequeña con aceptación, rutas exclusivas, reserva V3 y gates vigentes.

## Límites no negociables

- El alcance siempre es `tenant → entidad legal → sede/municipio`, con autorización server-side, mínimo privilegio y auditoría. `LegalEntity` no equivale a tenant ni concede visibilidad transversal; véase [arquitectura de entidades legales](arquitectura-entidades-legales.md).
- La decisión B de [#742](https://github.com/pl0n3r/Condor/issues/742) sigue vigente: RR. HH., asistencia y su persistencia/UI permanecen apagadas. #718 y #725 no son autorización de datos personales reales. Sin decisión sucesora, base jurídica y evidencia D-059/D-063 auténtica, solo se admite diseño y fixtures sintéticos.
- Un documento cargado no demuestra cumplimiento, una regla no sustituye una revisión profesional y un `success` de CI no acredita producción. Todo estado desconocido falla cerrado.
- No se guardan historias clínicas ocupacionales en el expediente administrativo. Los flujos médicos se limitan a conceptos de aptitud, recomendaciones y el mínimo metadato permitido, en un compartimento separado.
- No se crean cifras, fechas, tarifas, plazos ni obligaciones universales en código. Se versionan con fuente, vigencia, aplicabilidad y revisión responsable.

## Catálogo de módulos

| # | Módulo | Submódulos y resultado | Prioridad de producto | Dependencia o límite actual |
| --- | --- | --- | --- | --- |
| 1 | Talento humano | personas trabajadoras, cargos, áreas, sedes, organigrama, novedades, permisos, vacaciones, incapacidades, evaluaciones y portal | Crítica | Diseño bloqueado por #742; reutiliza `Employee`, no duplica identidad ni usuario. |
| 2 | Contratación laboral | plantillas revisables, contratos, otrosíes, firmas/evidencias, renovaciones, alertas, terminación y liquidación | Crítica | Nunca declara validez jurídica automática; depende de puertas RR. HH. y revisión humana. |
| 3 | SG-SST | perfil de estándares, matriz de peligros, controles, inspecciones, incidentes, investigación, EPP, capacitación, emergencias, comités/vigías | Crítica | Datos médicos segregados; perfil aplicable depende de tamaño, actividad y riesgo. |
| 4 | Nómina | periodos, conceptos versionados, novedades, horas, recargos, deducciones, prestaciones, desprendibles y salida electrónica cuando aplique | Crítica | Slice separado de RR. HH.; cálculo y transmisión nunca se presumen universales. |
| 5 | Seguridad social | afiliaciones, novedades, aportes, soportes, alertas y preparación para PILA | Crítica | No asume un régimen idéntico para toda persona; sin conectores o datos reales. |
| 6 | Cumplimiento legal | perfil legal, catálogo normativo, matriz de obligaciones/prohibiciones, calendario, evidencias, evaluaciones y auditorías | Crítica | Núcleo de #802; primero sin PII y con fuentes oficiales/versionadas. |
| 7 | Finanzas y contabilidad | ingresos/egresos, CxC/CxP, bancos, conciliación, presupuestos y cierres | Alta | Integra dominios existentes; no recrea ventas, compras, precios ni inventario. |
| 8 | Gestión documental | expedientes, clasificación, versiones, aprobación, firma/evidencia, retención, exportación y trazabilidad | Alta | Servicio transversal; acceso depende del dato/documento, no de una carpeta compartida. |
| 9 | Compras y proveedores | proveedores, cotización, solicitud, orden, recepción, evaluación, contrato y cuentas por pagar | Alta | Reutiliza compras/proveedores existentes y respeta `legal_entity_id`. |
| 10 | Activos empresariales | inventario de activos, asignación, responsable, garantía, mantenimiento, baja y evidencia | Alta | Un activo no es existencias para venta; toda baja requiere flujo autorizable y auditable. |
| 11 | Operaciones | procesos, procedimientos, tareas, órdenes de trabajo, responsables, tiempos y productividad | Alta | Motor de workflow configurable, sin convertir cada proceso en código fijo. |
| 12 | Riesgos empresariales | riesgos operativos, financieros, legales, tecnológicos y reputacionales; controles, incidentes, planes y seguimiento | Alta | Complementa, no reemplaza, el motor de cumplimiento y el SG-SST. |
| 13 | Calidad | indicadores, no conformidades, quejas internas, auditorías, causa, acción correctiva/preventiva y verificación de eficacia | Media | Una acción cerrada exige evidencia y aprobación; no se autocertifica. |
| 14 | Seguridad de la información | RBAC, accesos, revisiones, auditoría, copias, incidentes, continuidad y privacidad | Alta | Seguridad base no se monetiza; RBAC se mantiene separado de entitlements comerciales. |
| 15 | Gobierno corporativo | socios, órganos, representantes, facultades, actas, poderes, autorizaciones y decisiones | Media | No reemplaza asesoría societaria; se asocia a entidad legal y vigencia. |
| 16 | Servicio al cliente | PQRS, garantías, reclamos, casos, SLA configurables, comunicaciones y satisfacción | Alta | Distingue cliente comercial de usuario; reglas de consumo dependen de aplicabilidad. |

## Modelo transversal: una sola fuente por concepto

Las piezas compartidas evitan 16 aplicaciones aisladas:

```text
Tenant → LegalEntity → Branch/Municipio
                  ├─ Perfil de aplicabilidad
                  ├─ Roles y delegaciones
                  ├─ Documento/Evidencia versionada
                  ├─ Riesgo/Control/Obligación
                  ├─ Tarea/Alerta/Aprobación
                  └─ Evento de auditoría inmutable
```

Entidades transversales propuestas: `LegalProfile`, `RegulatorySource`, `NormVersion`, `ApplicabilityRule`, `Obligation`, `Prohibition`, `Assessment`, `Evidence`, `DocumentVersion`, `RetentionRule`, `Risk`, `Control`, `Finding`, `CorrectiveAction`, `Approval`, `AuditEvent`, `Task`, `NotificationPolicy` y `IntegrationConnection`. Cada una tiene tenant, contexto jurídico efectivo, actor, vigencia, estado y trazabilidad. Las entidades laborales, médicas, tributarias o de pago se mantienen en límites de dominio específicos y no se convierten en atributos genéricos de usuario.

## Flujos internos

### 1. Perfil y cumplimiento

1. Un administrador autorizado configura entidad legal, actividad/CIIU, municipio, sector, tamaño, régimen y fecha de referencia.
2. El motor resuelve reglas versionadas como `OBLIGATORIO`, `CONDICIONAL`, `PROHIBIDO`, `RECOMENDADO`, `NO_APLICA` o `REQUIERE_REVISION`, siempre con explicación, fuente, vigencia y condición.
3. Crea obligaciones, responsables y fechas sin afirmar cumplimiento.
4. Responsable adjunta evidencia o registra una acción; revisor con permiso distinto evalúa `PENDIENTE`, `EN_REVISION`, `CUMPLE`, `INCUMPLE` o `NO_APLICA`.
5. Alertas, hallazgos y auditoría muestran qué falta y por qué; nunca bloquean un acto legalmente indispensable.

### 2. Documento controlado

1. Se clasifica el documento y se aplica su política de acceso/retención.
2. Se carga una nueva versión con integridad, autor, vigencia y relación al proceso.
3. El aprobador competente acepta, rechaza o solicita cambio; la versión anterior permanece trazable.
4. Vencimiento, retención, exportación o eliminación siguen política y legal hold; no existe borrado silencioso.

### 3. Riesgo, calidad y mejora

1. Se registra riesgo, incidente o no conformidad con contexto y severidad.
2. Se asignan controles y un responsable; la evaluación registra evidencia, no solo una marca.
3. Una acción correctiva tiene plazo, verificación de eficacia y cierre aprobado.
4. Los tableros agregan sin exponer PII ni información médica/denuncias a roles no autorizados.

### 4. Ciclos laborales futuros (expresamente bloqueados)

Vinculación → contrato/otrosí → novedades → tiempo/ausencias → nómina y seguridad social → desvinculación/liquidación. Esta secuencia sirve solo para diseño con fixtures hasta que una autorización sucesora cumpla #742, D-059, D-063 y las dependencias declaradas. No se habilita captura, carga masiva, biometría, historias clínicas ni integración PILA.

### 5. Abastecimiento, activos y operación

Solicitud → cotización → aprobación → orden → recepción/aceptación → cuenta/soporte → evaluación de proveedor. Los activos siguen alta → asignación → mantenimiento/garantía → inventario físico → baja autorizada. Operaciones usa tareas y órdenes de trabajo referenciando el proceso, la sede, el activo y la evidencia sin mezclar propiedad entre entidades legales.

## Integraciones y fronteras

| Frontera | Contrato de integración |
| --- | --- |
| Comercio, pedidos, inventario, compras y producción | Consumir las entidades y APIs ya existentes; no duplicar catálogo, stock, cliente, proveedor ni costo. |
| Entitlements vs permisos | La contratación habilita una capacidad; RBAC decide quién puede usarla. Ambas condiciones son necesarias. |
| Documento/firma | Adaptador intercambiable, evidencia de consentimiento/firma y estado verificable; no prometer firma o validez antes de integrar proveedor y revisión aplicable. |
| Nómina, PILA, DIAN y facturación | Adapters detrás de contratos versionados; credenciales, transmisión y declaración real quedan fuera del primer alcance. |
| Correo/notificaciones | Mínimo dato, plantillas auditables, sin incluir PII ni detalles de expedientes en el asunto o cola. |
| BI/exportación | Filtro tenant + entidad legal + permiso en servidor; exportación auditable, con minimización y retención. |

## Permisos y seguridad

Roles orientativos: `platform_owner`, `tenant_owner`, `legal_reviewer`, `compliance_manager`, `hr_manager`, `sst_manager`, `payroll_manager`, `finance_manager`, `procurement_manager`, `asset_custodian`, `operations_manager`, `quality_manager`, `security_officer`, `corporate_secretary`, `customer_service_agent`, `auditor` y `employee_self_service`. No implican acceso mutuo por defecto.

Reglas comunes: autorización antes de leer y escribir; separación solicitante/aprobador cuando el riesgo lo amerite; sesiones y auditoría sin secretos; prohibición de enumeración cross-tenant; cifrado/controles proporcionados a sensibilidad; pruebas negativas de permisos; retención por finalidad; y segregación reforzada de denuncias, sanciones, salud ocupacional y datos sensibles.

## Referencias normativas colombianas y método de actualización

El catálogo normativo se construye con fuentes oficiales, texto consolidado y revisión de profesional competente. Referencia inicial, no exhaustiva: [Código Sustantivo del Trabajo y Ley 2466 de 2025](https://www1.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=260676), [Decreto 1072 de 2015](https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?emergente=1&i=72173), [Resolución 0312 de 2019](https://www1.funcionpublica.gov.co/documents/34645357/34703621/Resolucion_0312_de_2019.pdf/3c93008d-dd8e-8b0d-e5ea-ec6699db86e7), Ley 1562 de 2012, Ley 1581 de 2012, Ley 1480 de 2011, normas DIAN aplicables y resoluciones laborales relevantes. La fuente, artículo, emisor, versión, fecha de verificación, vigencia, condición y responsable de revisión son campos obligatorios. Una norma condicionada por CIIU, municipio, sector, tamaño, riesgo, régimen, ingresos, activos o forma jurídica nunca se modela como universal.

## Fases y backlog de desarrollo

| Fase | Resultado verificable | Módulos involucrados | Condición para avanzar |
| --- | --- | --- | --- |
| F0 | Taxonomía normativa y perfil de aplicabilidad con fixtures sintéticos | 6, 12, 14 | Fuente/versionado/revisión; sin PII ni reglas ejecutables no revisadas. |
| F1 | Motor de cumplimiento, matriz legal, evidencia, roles y auditoría | 6, 8, 14 | Aislamiento tenant/entidad, estados explícitos y pruebas negativas. |
| F2 | Calendario, riesgos, auditorías, calidad y centro de atención | 12, 13, 16 | Alertas sin fuga de información ni cierre automático. |
| F3 | SG-SST condicionado y compartimento médico | 3, 8, 12 | Modelo de acceso y retención revisado; datos sintéticos antes de cualquier activación. |
| F4 | Integración laboral, contratación, nómina y seguridad social | 1, 2, 4, 5 | Solo tras decisión sucesora a B, D-059/D-063 y hojas disjuntas. |
| F5 | Gobierno, activos, operación, proveedores, finanzas/tributario y servicio | 7, 9, 10, 11, 15, 16 | Reutilización demostrada de dominios existentes y adapters versionados. |
| F6 | Hardening, revisión jurídica/SST/contable, accesibilidad y piloto autorizado | Todos | Evidencia por slice, no declaración global de cumplimiento. |

Backlog inicial para descomponer bajo #802: perfil legal y catálogo normativo; reglas de aplicabilidad; matriz/evaluación/evidencias; documentos/retención; calendario/alertas; riesgos/controles/auditoría; SG-SST; integración de empleados existente; contratos; nómina; seguridad social; conectores tributarios/comerciales; activos; calidad; PQRS; y reportes auditables. Cada ítem requiere contrato de aceptación propio y no debe crear una segunda cola frente a #1.

## Criterios de aceptación transversales

- Dos empresas con perfil distinto reciben obligaciones distintas y explicables; una falta de contexto resulta `REQUIERE_REVISION`, nunca una exención inventada.
- La carga de evidencia no modifica por sí misma el estado a `CUMPLE`.
- Ninguna lectura, exportación, alerta o enlace revela datos de otro tenant, entidad legal o compartimento sensible.
- La vigencia normativa y los parámetros de cálculo son datos versionados, con fuente; no constantes escondidas en frontend o backend.
- Los módulos existentes de comercio/operación se consumen como fuente de verdad y cada duplicación propuesta se rechaza explícitamente.
- Los flujos RR. HH. reales permanecen inaccesibles mientras esté vigente la decisión B y falten las guardas legales.
- Cada entrega posterior prueba autorización, aislamiento, auditoría, error/vacío/denegación y accesibilidad en `es-CO`.

## Trazabilidad

- Roadmap único: [#1](https://github.com/pl0n3r/Condor/issues/1).
- Épico legal y secuencia de slices: [#802](https://github.com/pl0n3r/Condor/issues/802).
- Bloqueo de datos reales de RR. HH.: [#742](https://github.com/pl0n3r/Condor/issues/742), [#718](https://github.com/pl0n3r/Condor/issues/718) y [#725](https://github.com/pl0n3r/Condor/issues/725).
- Estado de módulos que ya tienen código: [Módulos operativos](modulos-operativos.md).
- Decisiones locales y privacidad: `AGENTES.md`, `decisiones.yml`, `datos.yml` y `docs/privacidad/`.

## Contrato de verificación integral — conciliación #1621 (solo documentación)

**Corte reproducible:** [auditoría #1543 / PR #1624 ya fusionado](https://github.com/pl0n3r/Condor/pull/1624) y [reporte de 41 filas](verificacion/gest-limits-norms-phases.md). El reporte contiene exactamente **41 identificadores distintos**, todos `NO_VERIFICABLE`. Los testigos previos son *parciales*, a veces solo negativos estáticos; una ruta de test no equivale a su ejecución y ni un CI exitoso acredita flujos E2E, cumplimiento legal o datos productivos. **No se promueve ninguna fila** en este contrato. La única hoja que consolida `docs/matriz-cobertura.md` es [#1553](https://github.com/pl0n3r/Condor/issues/1553); el archivo no se toca desde #1621.

### Partición exhaustiva de brechas y hojas ejecutables

| Bloque | Filas | IDs del reporte #1543 | Aserción conductual/recibo todavía requerido | Hoja con claims separados |
| --- | ---: | --- | --- | --- |
| Fuente normativa | 4 | `GEST-NORM-01`, `GEST-NORM-02`, `GEST-NORM-03`, `GEST-NORM-07` | Fuente oficial, versión y revisión humana por regla; rechazo de vigencia/aplicabilidad incompletas. | [#1638](https://github.com/pl0n3r/Condor/issues/1638) |
| Límites de seguridad | 5 | `GEST-LIM-01`, `GEST-LIM-02`, `GEST-LIM-03`, `GEST-LIM-04`, `GEST-LIM-05` | HTTP + MariaDB descartable: tenant, entidad y sede; RBAC/IDOR/CSRF, PII/compartimento médico, auditoría y negativos. | [#1637](https://github.com/pl0n3r/Condor/issues/1637) |
| Flujos 1–5 | 18 | `GEST-FLUJO-1`, `GEST-FLUJO-1-P01`, `GEST-FLUJO-1-P02`, `GEST-FLUJO-1-P03`, `GEST-FLUJO-1-P04`, `GEST-FLUJO-1-P05`, `GEST-FLUJO-2`, `GEST-FLUJO-2-P01`, `GEST-FLUJO-2-P02`, `GEST-FLUJO-2-P03`, `GEST-FLUJO-2-P04`, `GEST-FLUJO-3`, `GEST-FLUJO-3-P01`, `GEST-FLUJO-3-P02`, `GEST-FLUJO-3-P03`, `GEST-FLUJO-3-P04`, `GEST-FLUJO-4`, `GEST-FLUJO-5` | Recorrido conectado y permisos reales con roles distintos: documento/versiones/legal hold, riesgos/eficacia, compras/activos; FLUJO-4 solo DENEGADO (#742/B). | [#1620](https://github.com/pl0n3r/Condor/issues/1620) + [#1571](https://github.com/pl0n3r/Condor/issues/1571), [#1578](https://github.com/pl0n3r/Condor/issues/1578), [#1582](https://github.com/pl0n3r/Condor/issues/1582) |
| Fases F0–F6 | 7 | `GEST-F0`, `GEST-F1`, `GEST-F2`, `GEST-F3`, `GEST-F4`, `GEST-F5`, `GEST-F6` | Recibos por slice y gate. F0–F2/F5/F6 solo donde existen servicios; F3/F4 siguen fail-closed sin decisión sucesora a #742. | [#1638](https://github.com/pl0n3r/Condor/issues/1638) + [#1637](https://github.com/pl0n3r/Condor/issues/1637) |
| Aceptación AC01–AC07 | 7 | `GEST-AC-01`, `GEST-AC-02`, `GEST-AC-03`, `GEST-AC-04`, `GEST-AC-05`, `GEST-AC-06`, `GEST-AC-07` | Pruebas E2E de obligaciones diferenciadas, estado no auto-certificado, no fugas, normas versionadas, fuente de verdad, veto RR. HH., error/vacío/denegación/accesibilidad es-CO. | [#1638](https://github.com/pl0n3r/Condor/issues/1638) + [#1620](https://github.com/pl0n3r/Condor/issues/1620) |

La suma **4 + 5 + 18 + 7 + 7 = 41** coincide con la fuente de auditoría. Para cada fila, la columna «Brecha pendiente» del [reporte #1543](verificacion/gest-limits-norms-phases.md) explica por qué el resultado integral sigue sin acreditarse y conserva el testigo parcial o `SIN_TESTIGO_DIRECTO` cuando corresponde. Una hoja hija no cambia su estado hasta que sus aserciones, PR fusionado y alcance real se verifiquen.

### Contrato de evidencia antes de declarar un requisito cubierto

1. **Identidad y frontera:** ejecutar con al menos dos tenants, dos entidades legales y sedes distintas, roles de solicitante/revisor separados y MariaDB efímera/descartable. Verificar SQL y endpoint HTTP de forma conjunta, evitando suites que solo buscan nombres de archivos o cadenas en la documentación. Los permisos se exigen en el servidor, incluidas exportaciones, alertas y enlaces.
2. **Negativos que deben fallar si se elimina una guarda:** leer/cambiar tenant ajeno, cruzar entidad/sede, IDOR, CSRF, rol sin delegación, sesión ausente, fuente normativa sin vigencia, evidencia sin revisor, retención bajo legal hold, reintentos y fallo transaccional, PII en logs/colas y respuesta vacía/error. Un error de dependencia o una evidencia faltante se representa como `REQUIERE_REVISION` o denegación explícita, nunca `CUMPLE`.
3. **Normativa versionada:** toda obligación/prohibición requiere identificador de fuente oficial, texto/versión y fecha aplicable, jurisdicción y perfil/actividad de la entidad, intervalo de vigencia, autor de revisión competente, resultado motivado y huella de auditoría. Cambiar fecha, municipio, sector o versión altera la evaluación o fuerza `REQUIERE_REVISION`; ni cargar un PDF ni ejecutar el test sirve como certificación legal.
4. **Documento y riesgo:** nueva versión preserva versión previa, autor, hash, acceso mínimo, aprobación distinta y retención; `legal hold` impide eliminación/ocultamiento. Riesgo, hallazgo, acción correctiva y revisión de eficacia conservan actor, evidencia, severidad y auditoría; el cierre automático sin verificador falla. El agregado de reportes nunca revela datos médicos ni denuncias a roles generales.
5. **Fases:** F0/F1/F2/F5/F6 exigen al menos un recorrido realmente conectado y su recibo exacto (comando, fixture, base descartable, SHA, CI, aislamiento y límites pendientes). F3/F4 requieren **prueba negativa** de que RR. HH., SG-SST sensible y ciclo laboral continúan apagados bajo la decisión B de [#742](https://github.com/pl0n3r/Condor/issues/742), D-059 y D-063. Ni este contrato ni sus hojas autorizan persistencia laboral, biometría, expedientes médicos, PILA o datos reales.
6. **AC01…AC07 y accesibilidad:** por cada resultado transversal, ejercer estado sano, vacío, error y sin permiso, con actores diferentes; comprobar navegación y accesibilidad sobre UI auténticamente conectada en `es-CO`, y documentar lo que aún no tenga UI. Fixtures sintéticos no simulan una captura de producción; ausencia de UI/HTTP auténtica significa `NO_VERIFICABLE`.
7. **Promoción controlada:** solo triada código conductual + aserción que falla por regresión + PR realmente `MERGED` y recibo auténtico del HEAD adecuado. Estado `UNKNOWN` o evidencia incompleta conserva `NO_VERIFICABLE`; la actualización de la matriz y de sus totales corresponde únicamente a #1553. Todo PR conserva reserva V3 propia, rutas exclusivas, `Validar` exact-HEAD, seguridad y CodeRabbit FINAL conforme a PLAN-AGENTES.

### Secuencia sin invadir claims ni autorización

- **Negativos independientes:** [#1637](https://github.com/pl0n3r/Condor/issues/1637) tiene tests SQL y un reporte propios para fronteras de permisos, rollback y la denegación F3/F4. La falta de datos reales es condición permanente, no un defecto que se resuelva deshabilitando guards.
- **Flujos integrados existentes:** [#1620](https://github.com/pl0n3r/Condor/issues/1620) coordina la ruta HTTP sintética, pero depende de los contratos de documento [#1571](https://github.com/pl0n3r/Condor/issues/1571), compras [#1578](https://github.com/pl0n3r/Condor/issues/1578) y calidad [#1582](https://github.com/pl0n3r/Condor/issues/1582); no se confunde el servicio de riesgos previamente entregado [#1583](https://github.com/pl0n3r/Condor/issues/1583) con la integración global. Cualquier prueba fallida revela la brecha real del slice, sin inventar un adaptador.
- **Normas/fases/aceptación:** [#1638](https://github.com/pl0n3r/Condor/issues/1638) espera #1620 y cubre su propio flujo normativo/versionado y los recibos F0–F6 y AC01–AC07; no duplica los paths de #1637 ni de #1620.
- **Matriz y operación:** #1553 contrasta y consolida una vez fusionados los recibos. Ningún paso permite gastos, servicios, proveedores, secretos, operaciones productivas, tratamientos personales o go-live. Toda hoja se mantiene `NO_VERIFICABLE` hasta aportar evidencia individual genuina.

**Qué acredita este PR de #1621:** únicamente la trazabilidad y el contrato explícito de aceptación/rechazo de las 41 filas, con hojas disjuntas de implementación futura. **Qué no acredita:** que se hayan ejecutado nuevos E2E, restauraciones, normas legales completas o que F0–F6 estén listas para uso real.
