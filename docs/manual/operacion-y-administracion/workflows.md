# Manual de Automatizaciones · Workflows

## Para qué sirve

Permite preparar reglas para que una sede responda a determinados eventos, consultar su estado y ensayarlas sin efectos. Crear una regla **no la activa**.

## Antes de empezar

- Seleccione la empresa y sede correctas.
- Necesita autorización para administrar la configuración de esa sede.
- Confirme con la persona responsable qué evento y qué acción interna están permitidos. **Activar** una regla no es lo mismo que simularla.

## Cómo se usa (paso a paso)

1. Abra «Automatizaciones · Workflows». Revise «Workflows configurados» y utilice «Buscar por identificador o disparador» para encontrar una regla existente.
2. Pulse «Nuevo workflow». En «Crear workflow», complete «Identificador» y «Disparador», y elija una «Acción interna autorizada» entre las opciones disponibles.
3. Si hace falta, use «Agregar condición» y complete los datos que se solicitan. Puede usar «Quitar condición» antes de guardar.
4. Pulse «Crear desactivado». La nueva regla queda sin actividad hasta que alguien autorizado decida habilitarla.
5. En la lista, elija «Simular sin efectos» para ensayarla, o «Ver historial» para consultar registros existentes. Revise el resultado antes de usar «Activar»; use «Desactivar» cuando corresponda.

## Avisos y mensajes

- «Workflow creado desactivado. Actívalo cuando esté listo.» confirma la creación sin activación.
- «Simulación terminada. No se ejecutaron acciones ni se registró una ejecución.» indica que la prueba no hizo cambios reales.
- «Otro cambio actualizó el workflow. Recarga antes de continuar.» exige actualizar la información antes de insistir.
- «No tienes permiso para administrar workflows en esta sede.» indica que la cuenta no está autorizada.

## Limitaciones

La simulación es sintética: **no ejecuta acciones ni deja un registro de ejecución**. La disponibilidad de acciones depende de la sede y de sus permisos. No active una regla solo porque la simulación funcionó; compruebe primero su propósito y sus efectos previstos.
