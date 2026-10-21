# Política de cookies del sitio público — BORRADOR NO APROBADO JURÍDICAMENTE

**Estado:** documentado, no aprobado jurídicamente. **Ámbito verificado:** únicamente las páginas informativas de Condor `/`, `/producto`, `/soluciones` y `/seguridad` en el dominio de la plataforma. Este texto no representa una autorización de publicación ni una auditoría de navegadores/producción.

## ¿Qué son las cookies y qué observamos?

Una cookie es un dato que un sitio puede guardar en el navegador, por ejemplo para identificar una sesión. La cobertura HTTP automatizada de las **cuatro páginas informativas** comprueba que sus respuestas no emiten la cabecera `Set-Cookie` y que el HTML no incorpora scripts, rastreadores, iframes, recursos externos ni solicitudes de terceros. Los estilos de estas páginas son archivos locales del mismo origen. Los enlaces salientes, cuando existan, solo navegan tras la decisión del visitante; no autorizan cargas automáticas.

| Categoría en estas cuatro páginas | Cookies observadas | Finalidad | Duración |
| --- | --- | --- | --- |
| Analítica, publicidad y seguimiento | Ninguna | No se instalan mediante las respuestas HTML examinadas | No aplica |
| Cookies necesarias en las páginas informativas | Ninguna | No se establece sesión en estas respuestas | No aplica |

**Importante:** que estas cuatro respuestas no establezcan cookies no permite afirmar que *toda* la aplicación carece de cookies. Login, paneles autenticados, tienda de un tenant y servicios que el usuario abra mediante enlaces quedan **fuera de esta verificación**. Si una funcionalidad autenticada necesita una cookie de sesión, se deberá documentar su nombre real, finalidad, ámbito y caducidad tras verificar la configuración y el entorno, **antes de aprobar/publicar** una política integral. No se presume que las cookies de autenticación sean de seguimiento.

## Servicios externos y enlaces

La prueba inspecciona los recursos cargables del HTML y los recursos CSS locales servidos en el repositorio; los hipervínculos `<a href>` no constituyen por sí mismos una descarga de terceros. No afirmamos que las aplicaciones externas enlazadas apliquen esta política. Cambios futuros que añadan analítica, publicidad, píxeles, widgets, CDNs o recursos remotos requieren valoración previa, actualización de la política y controles de consentimiento conforme al marco aplicable.

## Derechos y contacto

Para consultas o ejercicio de derechos relacionados con datos personales se utilizarán los canales establecidos en la política de tratamiento de datos una vez identificados y aprobados por el responsable. **POR DEFINIR POR EL DUEÑO:** identidad y datos de contacto de quien actúa como responsable, canal oficial, identificación de cookies de otras áreas, retención real de sesiones y aprobación jurídica. No se publica una dirección ficticia ni se afirma que el texto cumple por sí solo todas las obligaciones regulatorias.

## Evidencia técnica y límites

- Contrato: Condor #787, AC-01, `tests/test_public_site_no_tracking.py::SitioPublicoSinRastreoTests::test_acceptance`.
- Ejecución: la aceptación invoca `Security`/Symfony `WebTestCase` a través de PHPUnit sobre las cuatro rutas, verifica códigos 200, ausencia de `Set-Cookie`, recursos cargables same-origin y ausencia de JavaScript/iframes. Las CSS propias se revisan para detectar solicitudes remotas.
- Las pruebas offline no sustituyen observación de navegador, CDN, reverse proxy ni cabeceras añadidas por infraestructura en producción. El despliegue, la auditoría legal y la publicación siguen sujetos a aprobación independiente.

**No introducir aquí:** nombres de cookies no comprobados, proveedores de analítica no instalados, caducidades inventadas, secretos o información personal.
