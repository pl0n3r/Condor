# F0 — Aplicabilidad normativa contextual (solo offline)

Este artefacto representa decisiones **sintéticas** de aplicabilidad por empresa y tenant. Es una especificación informática para ensayar futuros contratos; **no certifica cumplimiento**, no representa interpretación ni dictamen jurídico, y permanece **sin activación operativa** hasta revisión profesional y autorización expresa de las puertas existentes.

## Contrato y significado

NormativeApplicability::evaluate(rule, context, referenceDate) recibe una regla y un perfil inventados. rule requiere exactamente tenant_ref, kind, approved, reviewer_ref, source (kind=official, issuer, article, official_url, version), effective_from, effective_until, verified_on y conditions. context requiere exactamente tenant_ref, sector, ciiu, municipio, riesgo. conditions enumera al menos una de esas últimas cuatro dimensiones, con igualdad exacta. Las fechas son YYYY-MM-DD UTC, y la ventana de vigencia es inclusiva.

Un resultado kind de OBLIGATORIO, CONDICIONAL, PROHIBIDO o RECOMENDADO indica solo *clase de una regla humana revisada* y coincidencia de contexto; applicability=APLICA no acredita ejecución, control, documento ni evidencia. NO_APLICA exige una diferencia explícita entre condiciones y perfil completos de **un mismo tenant**. Si faltan datos, el tenant no coincide, la fuente es incompleta, no existe aprobación de revisión profesional, una fecha no es válida o la regla es futura/expirada, resulta REQUIERE_REVISION, no un falso cumplimiento. Se conserva la procedencia en casos de aplicabilidad verificable y no-aplicabilidad determinista.

La cadena kind=official, una URL HTTPS y approved=true son **atestaciones de un flujo de revisión futuro**, no prueba de autenticidad de una norma: el motor no navega ni verifica sitios oficiales. Solo un revisor legal habilitado puede documentar vigencia, fuente, excepciones y jurisdicción; estos fixtures usan example.invalid, no contienen obligaciones de Colombia que se puedan presentar como aprobadas.

## Puertas y seguridad

- **Sin datos personales**, usuarios/empleados reales, registros médicos, base de datos, API, UI, red, proveedores, secretos o archivos persistidos de tenants.
- **Sin activación operativa** ni incorporación de fuentes/reglas jurídicas reales antes de **revisión profesional** y aprobación del responsable.
- La aprobación F0 de #803 no habilita RRHH, nómina, SG-SST, certificación jurídica ni despliegue. La decisión B sobre #742 y D-059/D-063 siguen vigentes.
- D-043 requiere identidad y smoke/observer terminal VALIDATED_IN_PRODUCTION para promoción. Un PR de preparación reversible no representa producción verde.
- Reversión: quitar solo los tres archivos de esta hoja; no existen migraciones, tráfico ni recursos externos.
