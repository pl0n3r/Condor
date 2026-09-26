# Recuperación de contraseña: URL canónica y correo

## Origen canónico de los enlaces

El deploy Factory de Condor declara `domain: https://www.condorapp.com.co`. La configuración de Symfony utiliza ese **mismo origen HTTPS** como valor de respaldo para `app.canonical_url`. No se deduce la URL de las cabeceras Host/X-Forwarded-Host de una petición ni de un enlace aportado por el usuario.

`CONDOR_CANONICAL_URL` permite sobrescribir el valor en cada entorno, por ejemplo `https://condor.test` en CI. El constructor `PasswordResetUrlFactory` rechaza HTTP, URLs con credenciales, query, fragmento y tokens malformados. Una variable definida pero vacía o malformada no obtiene un enlace seguro y falla cerrado: debe corregirse la configuración. Antes de activar correo transaccional en un dominio distinto del definido por el deploy, el operador debe fijar `CONDOR_CANONICAL_URL` en el **entorno de ejecución de PHP** y verificar la URL emitida con el transporte de prueba; `domain` del workflow reusable no es una variable PHP.

No se registran ni se muestran aquí valores secretos; la URL canónica es un origen público.

## Estado del correo SMTP

En fase `construccion`, `CONDOR_MAILER_DSN`, `CONDOR_MAIL_FROM` y `CONDOR_MAIL_PROVIDER` se configuran únicamente cuando existe proveedor real aprobado y registrado en `datos.yml`. Sin los tres valores y sin proveedor documentado, el gateway no envía correo (fail-closed). El valor de respaldo de la URL **no activa SMTP ni autoriza pasar a live**.

Para verificar una futura activación: probar primero la recuperación completa con transporte de prueba, confirmar que el enlace usa el origen autorizado, que el token no aparece en el path ni en logs y que el segundo factor sigue activo. No habilitar el correo de producción mientras la puerta legal/de proveedor siga pendiente.
