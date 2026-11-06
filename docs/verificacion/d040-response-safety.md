# Condor — D-040-S08 y D-040-S16 · headers HTTP y HTML enriquecido (#1603)

**Fase:** construcción; evidencia sintética. Hoja del parent #1546.
La verificación de controles HTTP y del sanitizador **no certifica seguridad de producción**,
ni cubre uploads/SSRF (hoja #1604), redes externas, go-live o datos reales.

## Contratos observados

| Decisión | Control real | Prueba |
| --- | --- | --- |
| D-040-S08, headers de seguridad de administración | El suscriptor existente src/Infrastructure/Http/SecurityHeadersSubscriber.php registra kernel.response: CSP restrictiva, nosniff, política de referente y permisos, Cache-Control private/no-store para /admin y /api/v1; HSTS solo HTTPS/prod | SafeResponsePolicyTest::testRealAdminAndApiHttpResponsesCarrySecurityHeadersAndPrivateCachePolicy usa peticiones reales del kernel sobre /admin/login (200) y /api/v1/context sin login (401); comprueba valores y negativos CSP, no solo existencia de archivos |
| D-040-S16, XSS y contenido enriquecido | Nuevo servicio SafeResponsePolicy, disponible como filtro Twig opt-in condor_safe_html | Tests de etiquetas y atributos, URL autorizadas, URI maliciosas y ofuscadas, SVG/script/iframe, HTML malformado, entidades, tamaño límite y autoconfiguración Twig |

## Contrato de HTML enriquecido

La lista de **elementos permitidos** es cerrada: p, br, strong, em, b,
i, code, blockquote, ul, ol, li, a. Los contenedores div y span
solo conservan sus hijos, nunca atributos. Se eliminan por completo los subárboles
de elementos fuera de esa lista, los comentarios y cualquier atributo del usuario;
la **única excepción** es href en a si corresponde a HTTP/HTTPS absoluto
sin credenciales o a un path local con / (no // ni backslash). Todo enlace
aprobado recibe rel="noopener noreferrer nofollow". Ningún style,
onclick, SVG, img, script, data:, javascript: o URI protocol-relative
sobrevive. Texto y atributos de salida se escapan para HTML.

El parser DOM limita el tamaño del fragmento a 65536 bytes y usa LIBXML_NONET;
la salida solo puede contener etiquetas del catálogo. El servicio se registra
automáticamente en Symfony/Twig, pero **no sanitiza páginas completas** ni se
inyecta en plantillas existentes: solo deben invocarlo vistas que necesiten
mostrar HTML enriquecido no confiable. Por defecto Twig conserva autoescape;
no sustituirlo por raw. El test de plantilla verifica el filtro real.

## Regresión y límites

Ejecutar:

    vendor/bin/simple-phpunit tests/php/Infrastructure/Security/SafeResponsePolicyTest.php
    vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
    vendor/bin/rector process --dry-run --no-progress-bar

**AC-01:** las pruebas fallan si se eliminan la CSP del subscriber, los headers,
la conversión de texto, el rechazo de URLs, el sanitizer o el filtro Twig.
**AC-02:** exige además CI exact-HEAD, seguridad y squash por D-069.

La reversión es git revert del squash. No se modificaron rutas runtime
existentes, datos.yml, proveedores, secretos, despliegues ni migraciones.
La cobertura funcional documentada solo concierne a **respuestas HTTP** y
**sanitización opt-in**; la revisión de uso efectivo en consumidores futuros
continúa separada.
