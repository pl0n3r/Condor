# Guía del propietario de la plataforma Condor

Esta guía describe **las opciones que aparecen en el administrador del propietario**. Solo corresponde a la interfaz incluida en el código de Condor al 7 de noviembre de 2026. No certifica que una función esté habilitada en producción, ni autoriza a introducir datos reales o activar funciones de prueba. El acceso está reservado a quien tenga permisos de propietario de plataforma. Si una opción no aparece en tu sesión, no intentes acceder mediante enlaces ajenos: solicita la revisión de permisos por el canal autorizado.

## 1. Centro de control

### Para qué sirve
Presenta una visión general de empresas, personas usuarias, sedes y accesos, además de señales del funcionamiento de Condor.

### Antes de empezar
Entra con una cuenta autorizada. El acceso de una empresa no equivale al permiso global de plataforma.

### Cómo se usa (paso a paso)
1. Selecciona «Centro de control» en el menú.
2. Consulta «Empresas», «Usuarios», «Sedes» y «Membresías activas».
3. Si necesitas ver información de funcionamiento, pulsa «Abrir diagnósticos».

### Avisos y mensajes
Durante la carga puedes ver «Preparando centro de control…». Si faltan permisos aparece «Acceso no autorizado.». Si ocurre un error, «No pudimos cargar el centro de control.».

### Limitaciones
El resumen no permite deducir por sí solo que todo esté disponible en producción. Los datos mostrados dependen del acceso autorizado y del estado de la aplicación.

## 2. Empresas

### Para qué sirve
Consultar las empresas de la plataforma, revisar su información y acceder al formulario de alta.

### Antes de empezar
Confirma que estás en el contexto global y que tienes permisos para gestionar empresas. Evita incorporar información real en entornos de demostración.

### Cómo se usa (paso a paso)
1. Abre «Empresas».
2. Usa «Buscar por empresa o identificador» para filtrar la página visible.
3. Elige «Todas las empresas», «Con sedes» o «Sin sedes» según necesites.
4. Abre una empresa desde la tabla para revisar su detalle.
5. Si tienes autorización para darla de alta, pulsa «Crear cliente» y revisa el formulario lateral antes de enviar información.

### Avisos y mensajes
Cuando no hay resultados se muestra «Sin empresas para mostrar en esta página.». Si cambias de contexto, puedes encontrar «Modo propietario · Contexto de empresa» y el control «Volver al centro global».

### Limitaciones
Los filtros solo abarcan la página mostrada, no necesariamente todas las empresas. La presencia del botón de alta no concede permiso ni autoriza altas reales en un entorno de pruebas.

## 3. Planes y precios

### Para qué sirve
Consultar las versiones de los planes comerciales y, cuando el servidor lo permita, preparar cambios de tarifas, límites y capacidades. Configurar una tarifa no activa cobros.

### Antes de empezar
Accede con permisos de propietario y confirma que la edición está habilitada en tu instalación. No cambies condiciones comerciales sin autorización.

### Cómo se usa (paso a paso)
1. En el grupo «Comercial», selecciona «Planes y precios».
2. Usa «Buscar por nombre o clave» y el filtro «Tarifa» para ver «Todas», «Con tarifa», «Sin tarifa» o «Cotizar».
3. Abre un plan en «Planes y precios vigentes». Se muestra un panel lateral con «Precios», «Límites y capacidades» e «Historial».
4. En «Precios», revisa «Moneda», «Precio mensual (unidades menores; vacío = Sin tarifa)», «Precio anual (unidades menores; 0 = precio cero)» y «Requiere cotización». Antes de confirmar una modificación autorizada, pulsa «Revisar versión» y comprueba la vista previa; después «Confirmar versión».
5. En «Límites y capacidades», examina los campos y, solo si tienes autorización, usa «Guardar nueva versión». En «Historial», consulta los eventos disponibles del plan.

### Avisos y mensajes
Puede aparecer «Cargando autorización de edición…» o «No se pudo completar la operación. No se presupone ningún cambio.». Si cambió una versión, usa «Cerrar y recargar catálogo». Una tarifa registrada indica «Versión de tarifas registrada; el cobro real permanece desactivado.».

### Limitaciones
El editor está incorporado al código, pero esta guía no acredita su activación productiva ni autoriza cambios reales. Las tarifas se introducen en unidades menores; no se generan cobros por configurar una versión.

## 4. Plan y suscripciones

### Para qué sirve
Consultar el plan, el estado y la vigencia de las suscripciones de las empresas que puedes ver.

### Antes de empezar
Usa una cuenta con los permisos correspondientes. No tomes datos de demostración como importes o contratos reales.

### Cómo se usa (paso a paso)
1. Abre «Plan y suscripciones».
2. Consulta «Empresas visibles», «Con suscripción» y «Sin configurar».
3. Usa «Buscar empresa» y el filtro «Estado», con las opciones «Todos», «Configuradas» y «Sin configurar».
4. Selecciona la empresa cuya información quieras revisar.
5. Para volver a consultar los datos, pulsa «Actualizar suscripciones».

### Avisos y mensajes
Los listados se identifican como «Suscripciones de esta página». Los cambios, si tu instalación los permite, se comprueban antes de guardarse.

### Limitaciones
La búsqueda y las cifras visibles pueden corresponder solo a la página abierta. Consultar un plan no significa que exista una suscripción de pago o un cambio comercial autorizado.

## 5. Catálogo comercial

### Para qué sirve
Revisar los planes vigentes y la cartera comercial desde una vista principalmente de consulta.

### Antes de empezar
Accede con permisos de propietario de plataforma.

### Cómo se usa (paso a paso)
1. Abre «Catálogo comercial».
2. Consulta «Planes vigentes», «Empresas en cartera» y «Con suscripción».
3. Usa «Buscar empresa o slug» para encontrar una empresa dentro de la página visible.
4. Revisa «Cartera comercial» o «Catálogo vigente».
5. Si necesitas ir a la sección de planes, usa «Abrir editor de planes».

### Avisos y mensajes
Una cartera vacía puede mostrar «No hay empresas en la cartera mostrada.».

### Limitaciones
Esta vista presenta «Datos canónicos solo de lectura.». «Catálogo comercial» es una vista de consulta; el editor de «Planes y precios» está separado y no activa cobros. La búsqueda solo abarca la página cargada.

## 6. Staff y permisos

### Para qué sirve
Consultar el equipo de plataforma, gestionar invitaciones y ajustar permisos por cliente y módulo.

### Antes de empezar
Necesitas permisos específicos. No compartas enlaces de activación ni los registres en capturas o mensajes.

### Cómo se usa (paso a paso)
1. Entra en «Staff y permisos». La pantalla se titula «Staff de plataforma».
2. Usa «Buscar» y «Estado» para filtrar «Activos» o «Pendientes».
3. Para dar de alta una persona autorizada, pulsa «Invitar staff». El formulario lateral solicita «Nombre», «Correo», «Cliente» y «Módulo».
4. Selecciona las acciones permitidas, pulsa «Añadir permiso» y revisa «Permisos seleccionados» antes de «Crear staff e invitar».
5. Para ajustar accesos existentes, usa «Editar permisos» y después «Guardar permisos».
6. Para gestionar una invitación, utiliza «Reemitir invitación» o «Revocar invitación»; comprueba la acción y pulsa «Confirmar» o «Cancelar».

### Avisos y mensajes
El sistema puede mostrar «Cargando staff…», «Aún no hay staff de plataforma.», «No tienes permiso para consultar el staff de plataforma.» o «Permisos actualizados.». El «Enlace de activación de un solo uso» debe tratarse como privado.

### Limitaciones
Invitar o modificar permisos son acciones sensibles que requieren autorización. El formulario no está abierto inicialmente; se abre mediante el botón correspondiente. No se debe reutilizar ni divulgar un enlace de activación.

## 7. Diagnósticos

### Para qué sirve
Acceder a la sección de información sobre el funcionamiento y los incidentes de la plataforma.

### Antes de empezar
Usa una sesión autorizada de propietario. No publiques información técnica interna ni datos de personas.

### Cómo se usa (paso a paso)
1. Abre «Diagnósticos» en el menú o pulsa «Abrir diagnósticos» desde «Centro de control».
2. Consulta únicamente la información que tu sesión esté autorizada a mostrar.
3. Si el centro de control presenta «No pudimos cargar el centro de control.», conserva la referencia de error para la atención correspondiente sin compartir datos privados.

### Avisos y mensajes
El centro de control puede indicar «Acceso no autorizado.» o «Empresa no encontrada.» cuando no es posible mostrar el contexto solicitado.

### Limitaciones
El enlace al apartado no acredita por sí solo disponibilidad operativa del diagnóstico en todas las instalaciones. La guía no autoriza reparar producción, revelar identificadores privados ni interpretar un mensaje como confirmación de que el sistema está sano.

---

**Alcance de esta guía:** uso de la interfaz revisada en código, con datos ficticios durante las pruebas. No describe una puesta en marcha real ni concede autorización para gestionar información de clientes en producción.
