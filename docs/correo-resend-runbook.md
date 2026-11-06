# Resend para correo transaccional — configuración del propietario (#1725)

> Estado: **preparación offline / NO activado**. Resend fue elegido por el propietario en su **plan gratuito**. No crear cuentas, claves, DNS ni enviar correos desde este PR. Los tratamientos siguen bajo revisión jurídica antes de operación real.

## Ruta principal: Integraciones de `/adminpl0n3r` (cuando #1726–#1728 estén integrados)

1. El titular autorizado crea su cuenta Resend por canal privado, habilita MFA cuando esté disponible y verifica el dominio remitente de Condor (no usar dominios ajenos ni datos reales de prueba).
2. En Resend configura y verifica los registros DNS proporcionados para **SPF** y **DKIM**, y revisa la política **DMARC** del dominio. Comprueba en la consola del proveedor el estado *verified* y los diagnósticos del dominio antes de habilitar envíos.
3. Crea una clave de API nueva con privilegio **solo envío**, alcance mínimo y custodia del propietario. No pegarla en GitHub, chats, Issues, capturas ni logs.
4. Una vez desplegados y autorizados #1726 (almacén cifrado), #1727 (pantalla) y #1728 (consumo desde el almacén), el propietario abre `/adminpl0n3r` → **Integraciones** → **Resend**. Configura el proveedor `resend`, el remitente de su dominio verificado y la clave por un campo de **escritura únicamente**. Confirmar que la pantalla solo muestra el estado *configurado*, no el secreto. Estos controles **todavía no están disponibles** en este PR.
5. Verifica por canal autorizado el origen efectivo de configuración, la autorización jurídica/privacidad y la política de envío antes de permitir tráfico real. Un CI verde no significa que se haya conectado Resend, ni equivale a un envío probado.

**Importante:** `datos.yml` debe declarar `resend` en los tratamientos de envío. Este PR solo prepara esa declaración y documentación; no despliega ni activa el correo. El gateway actual solo lee variables de entorno; el cambio a la pantalla requiere explícitamente #1728.

## Respaldo temporal: variables de entorno, no configuración definitiva

Solo si el propietario autoriza una integración temporal **antes** de disponer de #1726–#1728, configurar fuera del repositorio, en el almacén protegido del servidor y nunca en archivos versionados:

```dotenv
CONDOR_MAILER_DSN="smtps://resend:CLAVE_DE_PRUEBA@smtp.resend.com:465"
CONDOR_MAIL_FROM="notificaciones@dominio-verificado.example"
CONDOR_MAIL_PROVIDER=resend
```

`CLAVE_DE_PRUEBA` y `dominio-verificado.example` son marcadores **no funcionales**. No son credenciales ni un remitente verificado. Sustituirlos solamente por el canal seguro del propietario; codificar la clave si contiene caracteres reservados de URL. Usar TLS (SMTP sobre 465), un remitente realmente verificado y configuración de permisos mínimos. `.env.example` mantiene las tres variables **vacías** intencionalmente para impedir activaciones accidentales por copiar el ejemplo.

El proveedor debe permanecer declarado en `datos.yml` y el gateway fallará cerrado cuando falte configuración. No modificar el PHP del gateway ni registrar excepciones que revelen DSN, destinatarios o tokens.

## Límite del plan gratuito y decisión de escala

La política inicial indicada por el propietario es **3.000 correos al mes y 100 al día**; confirmar cuotas vigentes en la consola oficial de Resend antes de operar. Registrar contadores diarios/mensuales sin destinatarios ni contenido, alertar preventivamente alrededor de **80/día** y **2.400/mes**, y detener/encolar sin reintentos masivos al llegar a los límites. Revisar entregabilidad, rebotes y la configuración de avisos sin exponer PII.

**Criterio de evaluación de plan pago:** si la demanda legítima proyectada supera consistentemente 100/día o 3.000/mes, o se acerca repetidamente al 80 % de esos topes, presentar al propietario cifras agregadas y un presupuesto. La elección de pago, la contratación y cualquier gasto requieren **autorización explícita**; ninguna automatización debe contratar o elevar el plan.

## Seguridad, validación y reversión

- Prueba de contrato: `python3 -m unittest tests.contract.test_resend_mail_config_contract`. Debe rechazar un proveedor no declarado, una clave con forma real y el uso de credenciales en `.env.example`.
- Comprobar la coherencia del registro de tratamientos sin tocar bases, cuentas, DNS, proveedor o tráfico real. Revisar las puertas D-059 y D-063 antes de live.
- Ante fallo o revocación, desactivar el ajuste desde el panel autorizado o vaciar las variables del entorno seguro, revocar la clave en Resend y mantener la cola en modo no disponible. Revertir el squash documental devuelve `datos.yml` a proveedores no declarados y restaura el bloqueo por defecto.

**No hay observación de producción, entrega SMTP ni verificación real del dominio acreditadas por este documento.**
