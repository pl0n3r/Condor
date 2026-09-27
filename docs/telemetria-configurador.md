# Telemetría privacy-safe del configurador

## Finalidad

Durante la fase de construcción, Cóndor usa una señal funcional agregable para comprobar el funnel del configurador y detectar en qué paso se abandona o completa la configuración. No es analítica de identidad, publicidad, CRM ni perfilado.

El endpoint público es `POST /api/public/configurator/events`. Solo acepta los eventos documentados `start`, `plan_selected`, `plan_changed`, `vertical`, `addon`, `abandonment`, `completion` y `proposal`. El contexto se reconstruye server-side exclusivamente con `event/plan/vertical/cycle/addon/step`; plan, vertical y add-on deben existir en el catálogo comercial canónico.

## Privacidad

La señal se persiste como `configurator_funnel` con `tenant_id = null`. No se guardan correo, nombre, IP, sesión, user-agent, cantidades, contenido libre, cookies, fingerprint ni identificadores cross-session. La IP puede usarse transitoriamente como clave del rate limiter dedicado, pero no entra en `FunctionalSignal` ni en el contexto funcional.

No se crean cookies ni identificadores persistentes. La instrumentación de navegador vive en #292 y debe respetar este contrato cerrado.

Como este slice no añade tratamiento de dato personal, `datos.yml` permanece intacto.

## Explotación y retención durante construcción

La explotación operativa usa la ventana móvil ya existente del reporte funcional: 30 días. Los conteos se agregan por día y tipo; el endpoint no expone eventos individuales al frontend público.

`FunctionalSignal` todavía no tiene una purga automática por antigüedad. Por ello, la retención técnica aplicable en construcción sigue siendo el lifecycle existente de esa tabla, aunque la explotación se limita a 30 días. #291 no ejecuta purgas destructivas ni borra señales previas. Antes de pasar a una fase con política de retención definitiva debe existir una tarea explícita de purga/archivo y su revisión legal correspondiente.

## Reversión

Retirar el controller, el limiter y el tipo `configurator_funnel` desactiva la captura nueva sin tocar Commercial Catalog, quotes ni datos personales. No hay migración nueva.
