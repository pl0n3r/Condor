# Baja de comunicaciones comerciales — Condor

> **BORRADOR NO APROBADO JURÍDICAMENTE.**
> Este texto es una regla técnica y una propuesta operativa para construcción;
> no constituye política publicada, asesoría jurídica ni evidencia de un mecanismo
> de baja desplegado. **POR DEFINIR POR EL DUEÑO**: validación comercial y jurídica.

## Regla obligatoria antes de habilitar correo comercial

Toda comunicación **comercial** o promocional enviada por Condor debe llevar un
enlace de **Cancelar suscripción** o **Dar de baja** que permita revocar el envío
de nuevas comunicaciones comerciales **sin iniciar sesión**. El enlace debe ser
accesible, reconocible y funcionar sin exigir el acceso a la cuenta, una compra,
una contraseña ni pasos adicionales de autenticación.

El mecanismo deberá usar una referencia opaca y acotada al destinatario/canal,
no poner datos personales en claro en la URL ni registrarlos en logs o analítica,
ser idempotente, impedir la reutilización abusiva y confirmar que la preferencia
comercial quedó desactivada. Los envíos comerciales posteriores deben respetar la
baja; no se autoriza volver a suscribir por silencio ni por una acción ajena.

**Plazo de atención propuesto:** máximo **2 días hábiles** desde la solicitud
válida; es un objetivo del **borrador**, **no un SLA vigente ni una afirmación
sobre la producción actual**. El plazo final y su comunicación pública son
**POR DEFINIR POR EL DUEÑO** con revisión jurídica previa. Hasta contar con un
enlace funcional, atención implementada y texto aprobado, no se habilitan
envíos comerciales que dependan de esta regla.

## Mensajes transaccionales exentos

La exención solo aplica a mensajes necesarios para la operación de una cuenta
y **sin contenido promocional**:

- **Recuperación/restablecimiento de contraseña**: facilitar un enlace temporal
  solicitado por la persona usuaria.
- **Invitaciones/activación de cuenta**: completar una invitación auténtica.
- **Avisos transaccionales esenciales**: seguridad, acceso o confirmaciones
  estrictamente relacionadas con una operación solicitada.

La exención no permite añadir ofertas, campañas ni publicidad al mismo
mensaje. Si hay promoción, se trata como comunicación comercial y requiere
enlace de baja. Las plantillas transaccionales que existan deben quedar
enumeradas explícitamente en la allowlist de la prueba.

## Estado de implementación, pruebas y decisión

Al elaborar este borrador, el árbol \`templates/**\` de Condor no contiene
plantillas Twig con \`mail\` o \`email\` en su ruta. Eso **no** acredita un sistema
de bajas funcional ni autoriza campañas: la prueba conserva casos de regresión
sintéticos para futuras plantillas comerciales.

La prueba \`tests/test_legal_unsubscribe_contract.py\` comprueba de forma
estática las plantillas Twig de correo y exige un enlace de baja identificable
en toda plantilla no transaccional. La verificación funcional de la ruta pública
de baja, la supresión efectiva de destinatarios y la atención del plazo
propuesto requieren implementación y pruebas HTTP independientes **antes de
producción**. En particular, un enlace sin endpoint funcional **no cumple** la
regla y no debe utilizarse para campañas.

Este documento no modifica el aviso de privacidad ni sustituye autorización
del responsable, consentimiento y demás obligaciones aplicables en Colombia.
