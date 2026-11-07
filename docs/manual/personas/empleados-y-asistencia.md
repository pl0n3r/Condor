# Manual de Empleados y asistencia

**Estado: pantalla de prueba previa a la puesta en marcha.** El acceso y las operaciones dependen de los permisos y de que el servicio esté habilitado. No debe considerarse una función disponible en producción.

## Para qué sirve

Permite consultar el directorio de empleados de la sede, reconocer cuáles tienen un usuario vinculado y revisar eventos de entrada y salida que el sistema ya haya registrado. No sirve para registrar una entrada o una salida desde esta pantalla.

## Antes de empezar

- Seleccione la sede correspondiente y acceda con los permisos de su empresa.
- Para crear un empleado, tenga preparado un código interno de hasta 32 caracteres.
- Si desea vincularlo a un usuario, solicite previamente el identificador correcto a la persona encargada de administrar las cuentas. El vínculo es opcional.
- No utilice información real durante las pruebas.

## Cómo se usa (paso a paso)

1. Abra «Empleados y asistencia». Compruebe el recuento de empleados y usuarios vinculados.
2. En «Buscar por código», escriba parte del código para localizar un empleado. La tabla muestra el código, el estado del vínculo y la opción de consultar asistencia.
3. Pulse «Ver asistencia» en el empleado deseado. Se abre «Asistencia del empleado» con los eventos disponibles, identificados como «Entrada» o «Salida».
4. Para incorporar un registro, pulse «Crear empleado», introduzca el valor de «Código del empleado» y, si corresponde, el identificador del usuario que se vinculará. Confirme con «Crear empleado».
5. Compruebe el aviso de confirmación y el directorio antes de volver a intentar una operación.

## Avisos y mensajes

- «No hay empleados registrados.» indica que todavía no aparecen empleados en el directorio.
- «Sin eventos de asistencia.» significa que no hay eventos para el empleado consultado.
- «Empleado creado.» confirma el alta.
- «Empleado creado; no se confirmó el vínculo.» significa que el alta se realizó, pero debe comprobarse el enlace opcional antes de repetirlo.
- «RR. HH. no está habilitado en este entorno.» o «No tienes permiso para gestionar empleados.» indican que no es posible continuar desde esta pantalla.

## Limitaciones

La consulta de asistencia no permite crear ni corregir marcaciones. El acceso, la creación y el vínculo están sujetos a permisos y comprobaciones del servidor; no se debe interpretar un error de conexión como una operación fallida ni repetir un alta sin verificar el directorio.
