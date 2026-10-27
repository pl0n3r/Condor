# Soporte y operación diaria de Condor — runbook pre-live

> Corte documental: 27-oct-2026. Producto en fase `construccion`. **No representa un servicio de soporte contratado, un SLA vigente ni una autorización de go-live**. Hasta que el OWNER complete las decisiones señaladas, los tiempos y canales de esta guía son **propuestas no activadas**. No se usan clientes, empresas ni datos personales reales.

## 1. Propósito, alcance y límites

Este procedimiento describe la atención manual a empresas clientes **cuando exista autorización para operar**, y permite un ensayo de mesa con incidencias sintéticas antes del lanzamiento. Aplica a consultas, incidentes de disponibilidad, degradación, seguridad, datos, facturación y derivaciones de producto. No habilita producción, modificación de tenants, procesamientos de PII, proveedores nuevos, compras ni administración de Hostinger.

Fuentes de código o documentación ya disponibles: `docs/knowledge-support-domain.md`, `docs/knowledge-support-retrieval.md`, `docs/knowledge-support-gaps.md`, `docs/knowledge-support-surfaces.md`, `docs/LIVE-UPTIME-RUNBOOK.md`, `docs/LIVE-AUTH-SMOKE-RUNBOOK.md` y `docs/lista-salida-produccion.md`. La ayuda pública `/ayuda` y `/faq` es conocimiento SSR; `/admin/ayuda` utiliza el contrato `support-context-v1` y puede mostrar `handoff`. **Ninguna de esas pantallas crea por sí sola un ticket, un canal humano atendido o una promesa de respuesta.** Los knowledge gaps agregados son señales minimizadas, no registros de clientes.

## 2. Canales, horarios y guardia

| Medio | Uso previsto | Estado real previo al live | Responsable |
| --- | --- | --- | --- |
| `/ayuda`, `/faq` y `/admin/ayuda` | Autoservicio y derivación `handoff` cuando la evidencia no alcanza | Superficies de conocimiento; no constituyen mesa de ayuda atendida | Ingeniería mantiene el contrato técnico |
| Canal privado de recepción de incidencias | Primer contacto de empresas y acuse de recibo | **PENDIENTE_OWNER**: definir un medio verificado, autorizado y con política de privacidad; no se publica contacto supuesto | OWNER debe designar soporte |
| Canal privado de emergencia y suplencia | S1, seguridad o proveedor caído | **PENDIENTE_OWNER**: guardia y sustituto reales sin exponer identificadores | OWNER debe nombrar responsables |
| GitHub Issue técnico minimizado | Seguimiento sin material personal, secretos ni tenant IDs | Solo para incidencias técnicas sanitizadas; no es CRM ni bandeja privada | Ingeniería / SRE |

**Horario propuesto, no activado:** lunes a viernes, 09:00–17:00 en `America/Bogota`, excluidos festivos colombianos. Solo rige después de decisión escrita del OWNER, nombramiento de personal y prueba del canal. Fuera de esa franja **no existe cobertura garantizada** ni se simula una guardia; el OWNER debe definir si se contratará atención extendida y cómo se derivará un S1. No iniciar onboarding de empresas hasta disponer de canal y responsable verificables.

## 3. Responsables y autoridad

| Rol | Función acotada | Reemplazo y límite |
| --- | --- | --- |
| Solicitante de empresa | Reporta síntomas por el canal privado aprobado; se le pide minimizar información | Nunca recibe instrucciones para pegar contraseñas o datos de terceros |
| Agente de soporte humano | Verifica canal, genera referencia opaca, clasifica severidad, responde y documenta seguimiento | **PENDIENTE_OWNER**: titular y suplente; no tiene autorización implícita para desplegar |
| Ingeniería / QA | Diagnostica con señales sanitizadas, reproduce con fixtures sintéticos, ofrece corrección y validación | Necesita reserva V3 y CI exact-HEAD para cambiar código |
| SRE / infraestructura | Examina `/health`, uptime y evidencias autorizadas, propone contención reversible | No cambia DNS, hosting, backups ni secretos sin puerta pertinente |
| Seguridad / privacidad | Valora sospecha de incidente de datos y define ruta restringida | No vuelca detalles personales en repos públicos |
| OWNER | Decide canal, horario, SLA contractual, presupuesto, personal, proveedores, comunicaciones externas y go-live | Única autoridad sobre gasto, cuentas, datos reales y activación operativa |

**Regla de sustitución:** si el titular no está asignado o no responde, el estado de asignación queda `UNKNOWN`; escalar al OWNER por el canal privado ya autorizado cuando exista, sin inventar aceptación de incidentes ni paginar a un destinatario inexistente.

## 4. Severidades y objetivos de primera respuesta

| Severidad | Disparador verificable (ejemplos, no promesas de producto) | Primera respuesta objetivo, propuesto | Escalamiento |
| --- | --- | --- | --- |
| `S1` crítico | Indisponibilidad general confirmada por dos probes, incidente de seguridad grave o pérdida de aislamiento entre empresas | 30 min en horario **aprobado**; fuera de horario, sin tiempo comprometido | OWNER + SRE + seguridad de inmediato si existe guardia activada |
| `S2` alto | Checkout o autenticación indisponible para un ámbito relevante, sin evidencia de impacto global | 2 h hábiles durante cobertura aprobada | Soporte + ingeniería; OWNER si requiere cuenta/proveedor |
| `S3` medio | Función degradada con alternativa manual documentada y sin exposición de información | 1 día hábil durante cobertura aprobada | Soporte + equipo funcional |
| `S4` bajo | Consulta, sugerencia o solicitud de documentación sin falla operativa | 3 días hábiles durante cobertura aprobada | Soporte; crear gap minimizado según Knowledge v1 |

**Los tiempos anteriores son objetivos propuestos, no SLA firmado, disponibilidad de personal ni garantía.** El OWNER debe aprobar o reemplazar valores, canal, calendario y mecanismo de cronometraje antes de ofrecerlos comercialmente. El reloj de primera respuesta empieza solo con una recepción auténtica por canal aprobado; no se calcula desde un mensaje sin acuse. Reconocer un mensaje no equivale a resolver el incidente.

Si la telemetría de `docs/LIVE-UPTIME-RUNBOOK.md` es ausente, tardía o contradictoria, el estado es `UNKNOWN` y **jamás `HEALTHY` por omisión**. Un workflow de GitHub terminado en verde no acredita el estado de un cliente ni el smoke productivo.

## 5. Procedimiento: recepción, triaje, diagnóstico y escalamiento

1. **Recepción controlada.** Solo por canal privado autorizado; emitir una referencia interna opaca generada por el operador. No reenviar cuerpo de mensajes, enlaces con query, archivos ni identidades a GitHub. Acusar recibo con la plantilla de la sección 6 sin prometer horario no aprobado.
2. **Minimización.** Solicitar únicamente módulo afectado, clase de síntoma, franja UTC aproximada y un ejemplo **sintético** reproducible. Nunca pedir contraseña, cookie, captura con PII, correo, teléfono, documento, tenant ID, IP, base de datos ni token. Si el reporte contiene datos sensibles, derivar a seguridad por vía restringida y no copiarlo al ticket técnico.
3. **Clasificación.** Registrar `S1|S2|S3|S4` con justificación tipada y distinguir `HEALTHY`, `DEGRADED`, `UNKNOWN` o `TRANSIENT` según el monitor. No asumir impacto productivo por una sola señal o por un test local.
4. **Verificación read-only.** Consultar únicamente señales permitidas: `/health`, run canónico de uptime, versión, SHA y métricas agregadas sin cuerpos de respuesta. Comprobar fecha UTC y coincidencia de origen; si faltan pruebas, dejar `UNKNOWN`, no fabricar diagnóstico. No ejecutar consultas de clientes ni activar autenticación sintética si su puerta está apagada.
5. **Ruta de ayuda.** Consultar `/ayuda`, `/faq` o `/admin/ayuda` según audiencia y permiso. Un `handoff` o Knowledge Gap no es una respuesta final ni un ticket automático; soporte valida la vigencia de la fuente y deriva manualmente.
6. **Escalamiento.** Para `S1`, seguridad, posible filtración, aislamiento entre tenants o pérdida de datos, avisar a OWNER y especialistas aprobados por canal privado. Para `S2` o plazos propuestos incumplidos, escalar a ingeniería y al responsable operativo real si existe. Sin personal/canal o autorización, mantener `PENDIENTE_OWNER` / `UNKNOWN`; no declarar cobertura.
7. **Contención y corrección.** Priorizar acción reversible y ensayo sintético. Cambios de código requieren issue, reserva V3, PR, CI/Validar exact-SHA y las reglas de Factory #767; deploy, DNS, pagos, secretos, restore de clientes, SQL destructivo y go-live exigen autorización explícita y gates de cada caso. No alterar producción desde este runbook.
8. **Actualización y verificación.** Comunicar estado en hitos comprobados: recibido, en diagnóstico, mitigación autorizada, observado recuperado, o aún `UNKNOWN`. Evitar horas prometidas y causas no verificadas. Comparar evidencia posterior con la previa y separar PR fusionado de despliegue y validación productiva.
9. **Cierre del incidente.** Exigir aceptación por el rol autorizado, criterio observable, resultado de prueba, instante UTC, SHA/versión cuando aplica, y estado `resuelto|en_seguimiento|UNKNOWN`. Un `success` de CI o una respuesta HTTP aislada **no** cierran por sí mismos un incidente. Si no hay recibo verificable, dejar abierto/en seguimiento y comunicar el próximo responsable **solo si está confirmado**.
10. **Aprendizaje.** Crear, si procede, un issue técnico sanitizado o Knowledge Gap agregado para el fallo recurrente; conservar solo fingerprints y referencias permitidas. No copiar conversaciones, transcripciones, correos o capturas. El OWNER decide si habrá mecanismo de retención/atención conforme al análisis jurídico.

## 6. Plantillas de comunicación sin datos reales

**Acuse de recibo** (solo cuando exista canal y operador autorizado):
> Hemos recibido el reporte con referencia interna [REF_OPACA]. Estamos verificando el alcance y compartiremos el próximo hito por este mismo canal aprobado. El tiempo de respuesta depende de la cobertura acordada; no solicitamos credenciales ni datos personales en este intercambio.

**Actualización / degradación o evidencia insuficiente:**
> Referencia [REF_OPACA]. Estado verificado [DEGRADED|UNKNOWN|EN_DIAGNÓSTICO]. El equipo responsable [ROL_CONFIRMADO] revisa señales técnicas mínimas. No afirmamos recuperación ni plazo definitivo sin observación suficiente.

**Escalamiento de seguridad o S1:**
> Referencia [REF_OPACA]. La gravedad amerita revisión especializada. Se activará el procedimiento de comunicación restringida y autorización correspondiente; no incluimos detalles sensibles en el seguimiento público.

**Cierre o seguimiento:**
> Referencia [REF_OPACA]. Resultado [RESUELTO|EN_SEGUIMIENTO|UNKNOWN], observado en [UTC_VERIFICADO] con evidencia sanitizada [REF_EVIDENCIA]. El cierre definitivo depende de la validación de la persona responsable; si faltan recibos, continúa en seguimiento.

Estas plantillas son ejemplos de preparación, no mensajes enviados, ni una política de notificación automática.

## 7. Registro de evidencias, aceptación y control de privacidad

La ficha técnica **sanitizada** (no CRM ni ticket con PII) debe conservar solo nombres de campos y valores tipados aprobados, cuando exista un operador autorizado:

| Campo permitido | Regla de validación |
| --- | --- |
| `incident_ref` | Referencia opaca generada por soporte, sin identidad ni prefijo de empresa |
| `severity` | `S1|S2|S3|S4`, más categoría técnica cerrada |
| `phase` | `received|triage|investigating|mitigated|resolved|UNKNOWN` |
| `observed_at_utc`, `updated_at_utc` | Instantes verificables, sin inferencia de ejecución |
| `module`, `evidence_ref` | Módulo allowlisted y referencia opaca sin parámetros privados |
| `release_sha`, `version` | Identidad técnica únicamente cuando la fuente exacta esté verificada |
| `owner_role`, `next_action` | Rol autorizado y acción tipada, jamás datos del contacto |
| `closure_receipt` | Evidencia mínima de validación, o `UNKNOWN` si falta |

Prohibido copiar a GitHub/CI nombres, correos, teléfonos, documentos, tenant IDs, direcciones, IP, secretos, cabeceras, cookies, tokens, contenido privado, payloads o respaldos. No almacenar transcripciones de chat. El canal privado real y su retención/política requieren aprobación jurídica y configuración del OWNER. Una incidencia de seguridad se trata en un canal restringido aprobado y solo se publica la referencia sanitaria mínima si procede.

## 8. Rutina diaria y ensayo sintético

**Apertura de jornada (si hay guardia aprobada):** comprobar quién atiende y su suplente; validar que el canal recibe una prueba sintética; revisar nuevas incidencias sanitizadas, ordenarlas por severidad y consultar la frescura del uptime. Ausencia de runner, canal o responsable => `UNKNOWN` y escalamiento al OWNER. No ejecutar acciones sobre cuentas reales.

**Durante la jornada:** separar solicitudes funcionales de incidentes; registrar referencias mínimas, acuse, evidencias, escalamiento y actualización con fechas UTC. Revisar Knowledge publicado y fresco; el estado `handoff` requiere trato humano real, no un bot inventado.

**Cierre de jornada:** revisar casos sin recibo de resolución, relevo y ventanas autorizadas; anotar pendientes y capacidad de atención real. No transformar incidentes sin evidencia en `resolved`, ni declarar operación 24/7 por un cron.

**Ensayo sin clientes:** simular cuatro casos sintéticos `S1` a `S4`, con una señal `UNKNOWN`, un intento de pegar credenciales (se rechaza sin conservar el valor) y un `handoff` de `/admin/ayuda` sin evidencia. Probar la clasificación, los roles, los borradores de mensajes, el escalamiento y el criterio de cierre en papel, sin enviar solicitudes externas, activar canales, consultar cuentas ni usar datos reales. El resultado de este ejercicio es solo `DOCUMENTED_FOR_REVIEW`, nunca `VALIDATED_IN_PRODUCTION`.

## 9. Puertas del OWNER pendientes antes de ofrecer soporte

- **PENDIENTE_OWNER — canal y cuenta:** seleccionar y aprobar herramienta, canal privado, protección de datos, acceso y conservación; no existe ahora mesa de ayuda contractual probada.
- **PENDIENTE_OWNER — titulares:** designar agente humano, suplente, SRE y seguridad, con confirmación de aceptación de turnos.
- **PENDIENTE_OWNER — horario y SLA:** aprobar calendario, festivos, objetivos S1-S4 y atención fuera de horario; los números de esta guía son propuestas.
- **PENDIENTE_OWNER — presupuesto y proveedores:** aprobar cualquier plan de ticketing, correo, alertas, telefonía u outsourcing antes de gasto.
- **PENDIENTE_OWNER — privacidad y legal:** validar condiciones de soporte, tratamiento, retención, aviso y contratos de empresas clientes.
- **PENDIENTE_OWNER — activación:** autorizar expresamente go-live y datos reales en Condor, preservando D-063, decisiones de RR. HH. y controles de producción.

**Definición de listo de este slice:** documento y pruebas en `main` con `Validar` terminal success sobre el HEAD del PR; esto es documentación técnica lista para revisión, **no soporte activado**. La hoja de checklist `#980` y #985 son trazabilidad, no prueba de que se esté atendiendo a empresas.
