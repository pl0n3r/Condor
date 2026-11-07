# Sentry: datos mínimos y fail-closed (#647)

Estado: construcción. **No** establece un DSN real ni ejecuta envíos por esta hoja; no sustituye la revisión
jurídica pendiente del tratamiento `error_incidents` (#617). `config/packages/sentry.php` no cambia.

## Configuración del DSN desde el propietario (#1730)

La pantalla del propietario /adminpl0n3r gestiona el campo Sentry. El backend cifra
el secreto global como sentry.dsn en el almacén #1726 (nunca por tenant, en una
respuesta de API ni en la bitácora). La lectura se realiza al resolver
SENTRY_DSN en ejecución: **el almacén cifrado tiene prioridad sobre el entorno**.
Si el registro no existe, la BD falla o el cifrado es inválido, se usa la variable
del entorno como respaldo. Sin ninguno, el DSN es vacío y no hay transmisión.

StoredSentryDsnEnvLoader implementa EnvVarLoaderInterface y, para respetar
la prioridad también cuando ya existe una variable de entorno, implementa
EnvVarProcessorInterface exclusivamente para el procesador string.
Los demás nombres conservan el procesamiento estándar de Symfony y el
cargador nunca publica otro nombre distinto de SENTRY_DSN. La conexión
es diferida y el secreto se guarda temporalmente en memoria del proceso,
no en logs. Los fallos (tabla, BD, APP_SECRET o autenticación del cifrado)
retornan vacío sin excepción ni revelar causa o DSN.

**Seguridad:** este cambio no configura ningún DSN real, no activa Sentry
productivo ni autoriza salida de datos. Se mantiene la puerta legal #617,
la revisión humana de seguridad de esta hoja y el saneado de eventos.

## Qué puede salir en un evento

Solo telemetría mínima y segura:

- la **clase** de cada excepción y su traza (archivo, línea y función, **sin variables locales**);
- `release`, entorno, nivel, marca de tiempo, versión de PHP y del sistema, módulos del SDK;
- los identificadores hexadecimales de traza (`trace_id`, `span_id`, `parent_span_id`);
- de la petición: método, URL sin query, sin fragmento y con el path reducido, y las cabeceras
  `X-Request-Id`, `Content-Type` y `Accept`.

## Qué se descarta siempre

| Dato | Tratamiento |
| --- | --- |
| Mensaje de excepción y mensaje del evento | `[redacted]`: pueden incrustar credenciales, correos o ids |
| Breadcrumbs, `extra`, etiquetas (tags), `fingerprint`, `transaction`, `logger`, nombre de servidor, logs y métricas | vaciados |
| Contextos | solo `trace` con ids hexadecimales; cualquier otro, incluidas sus subestructuras, se elimina |
| Usuario, cookies, cuerpo (`data`), `query_string`, `env` y el resto de cabeceras | eliminados (lista cerrada, no lista de bloqueo) |
| Path de la URL | cada segmento que no sea una palabra en minúscula (`/reset`, `/health`) pasa a `[redacted]`; esquema distinto de http/https → URL `[redacted]` |

## Fail-closed

- Solo se envían eventos de error; transacciones, check-ins y demás tipos devuelven `null`.
- Cualquier excepción durante el saneamiento o un cambio en la estructura interna del SDK (los
  contextos se reconstruyen porque la API pública no permite quitarlos) devuelve `null`: el evento
  no se transmite.
- `send_default_pii=false` y un DSN vacío no se consideran redactores: el saneamiento no depende de ellos.

## Verificación

`tests/php/Infrastructure/Observability/SentryEventSanitizerTest.php` serializa el evento con el
`PayloadSerializer` del SDK y comprueba que ningún valor sintético (contraseña, correo, clave de API,
identificador, token) aparece en lo que se transmitiría, incluidas estructuras anidadas, y que se
conservan clase, release, entorno, nivel, traza sin variables y la URL segura. El contrato Python
`tests/test_ci_sentry_privacy_contract.py` es estructural: verifica que esas garantías y pruebas
existen y **no** sustituye a PHPUnit, que se ejecuta en `Backend PHP / MariaDB` sobre el SHA exacto.
