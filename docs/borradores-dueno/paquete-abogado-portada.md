# Portada para el abogado de privacidad (Condor, GrindFlow y brvtal)

Este documento no es asesoría ni aprobación legal. Resume qué entregar, qué debes completar tú antes y qué preguntas debe responder el abogado. Los textos legales ya existen: no hay que escribirlos de nuevo.

## 1. Qué entregarle

| Producto | Carpeta | Archivo único para enviar |
|---|---|---|
| Condor | `revision-juridica/` | `paquete-completo.md` (siete documentos) y `PAQUETE-ABOGADO.md` (los siete repos) |
| GrindFlow | `revision-juridica/` | `paquete-completo.md` (siete documentos + operación de derechos e incidentes) |
| brvtal | `revision-juridica/` | `paquete-completo.md` (seis documentos) |

Cada carpeta empieza con `00-LEEME.md`, que explica qué debe confirmar o corregir. Las correcciones del abogado se aplican primero en las fuentes (`docs/privacidad/`), y luego se regenera la carpeta. Estado actual de los tres: `documented_not_legally_approved`.

## 2. Lo que tienes que completar tú antes de enviarlo

Siguen con `[COMPLETAR POR EL DUEÑO]` en los tres productos. Sin estos datos el abogado no puede cerrar nada:

- [ ] Nombre o razón social del responsable.
- [ ] NIT o identificación.
- [ ] Domicilio y dirección de notificaciones.
- [ ] Correo o canal para que las personas ejerzan sus derechos.
- [ ] Quién tiene autoridad para firmar documentos (representante).

No escribas datos personales reales dentro de los repositorios públicos: deben viajar por fuera, en el correo al abogado.

## 3. Proveedores declarados que el abogado debe confirmar

| Producto | Declarados en `datos.yml` (no significa que estén activos) |
|---|---|
| Condor | Sentry; también Hostinger (hosting) y GitHub Actions (automatización) |
| GrindFlow | Cloudflare R2, Dropbox, Google Drive, Meta Instagram, Supabase |
| brvtal | Google Analytics, proveedor SMTP de correo |

Para cada uno: si se usa de verdad, qué rol jurídico tiene (encargado o tercero), contrato y posibles transferencias internacionales.

## 4. Preguntas que debe contestar

1. ¿Cuáles son las bases legales y el consentimiento necesarios por finalidad?
2. ¿Los plazos de conservación de cada tabla de retención son correctos y suficientes?
3. ¿El canal de derechos y sus plazos cumplen la ley colombiana de protección de datos?
4. ¿Cada proveedor declarado es encargado, tercero o ninguno? ¿Hay transferencias internacionales y qué garantías exigen?
5. ¿Los términos y condiciones cubren obligaciones, niveles de servicio, jurisdicción y reclamaciones?
6. ¿Hay que registrar bases de datos ante la autoridad competente?
7. ¿Hay datos de menores o datos especiales en algún flujo (por ejemplo, creadores de GrindFlow)?
8. ¿Cuál es el dictamen por versión que debe referenciarse para cerrar cada puerta legal?

## 5. Qué desbloquea su respuesta

- Condor #974 (decisión jurídica sucesora) y Condor #1497 (textos y contratos por empresa).
- GrindFlow #457 (revisión de privacidad) y la activación del flujo de derechos sobre datos.
- brvtal: la autorización final de lanzamiento con respaldos y legal.

## 6. Nota sobre el «voto» pendiente de Condor #974

Ya publicaste dos veces `/decidir A` en #974. El bot no emitió el registro oficial por un bloqueo técnico de Factory v1, no porque falte tu decisión. No tienes que repetirlo.
