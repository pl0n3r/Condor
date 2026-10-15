# Sentry: datos mínimos y fail-closed (#647)

Estado: construcción. **No** habilita `SENTRY_DSN`, no envía eventos y no sustituye la revisión
jurídica pendiente del tratamiento `error_incidents` (#617). `config/packages/sentry.php` no cambia.

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
