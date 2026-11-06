# D-040-S17/S18 — entradas externas sintéticas (#1604)

**Fase:** construcción. Esta hoja no certifica que exista un upload o un fetch
HTTP real integrado, ni acredita seguridad en producción. Todas las pruebas
son bytes ficticios y respuestas DNS inyectadas, sin red, secretos ni clientes.

## D-040-S17 — archivos

`ExternalInputPolicy::inspectUpload()` analiza los bytes con `finfo`;
rechaza vacíos, >5 MiB, formatos fuera de PDF/JPEG/PNG, MIME declarado
distinto del detectado, imágenes inválidas o con >25 millones de píxeles
y nombres cliente con rutas/controles. Devuelve exclusivamente un nombre
interno aleatorio de 128 bits y extensión derivada del tipo real; no conserva
rutas, nombres ni extensiones suministradas. Regresión: quitar cualquiera
de estos guards rompe las aserciones negativas de
`ExternalInputPolicyTest`. Un nombre opaco **no** implica almacenamiento
seguro ni análisis antimalware.

**Al integrar uploads reales:** usar almacenamiento fuera del webroot,
cabeceras para impedir ejecución/inline, permisos y límites de memoria/
tiempo, inspección antimalware según exposición, y tests HTTP+tenant. Esos
consumidores están fuera de los claims de #1604.

## D-040-S18 — destinos y redirects

`inspectExternalUrl()` acepta solo `http`/`https` en puertos 80/443,
sin credenciales ni fragmentos; valida forma de host, impide localhost/
.local/.internal y deniega direcciones privadas, loopback, link-local,
metadata (169.254.169.254), CGNAT, benchmark, reservadas y respuestas DNS
mixtas o no verificables. Resuelve únicamente mediante una callback
inyectada; no hay peticiones de red desde esta clase.

`inspectRedirectChain()` admite máximo 3 saltos, resuelve y valida **cada**
destino y bloquea downgrade HTTPS→HTTP. Las pruebas revalidan un DNS que
cambia de pública a privada, un redirect a loopback, credenciales, protocolos
maliciosos y puertos no permitidos. Las IPs verificadas se devuelven como
`approved_ips` para **pinning**: el futuro adaptador HTTP deberá conectar
exclusivamente a la IP verificada en cada salto, deshabilitar redirects
automáticos, fijar budgets de tiempo/bytes, no enviar cookies/secretos
internos y evitar nueva resolución DNS en la conexión. Validar una URL y
luego hacer `fetch(url)` sin pinning **no es seguro**; se prohíbe.

## Aceptación y límites

- Pruebas: `tests/php/Application/Security/ExternalInputPolicyTest.php`.
- CI exigido: `CI Factory v1 / Validar` exact-HEAD, calidad y revisión final.
- Sin DNS real, solicitudes HTTP, usuarios/archivos reales, providers,
  deploy ni permiso para habilitar go-live.
- Reversión: retirar tres archivos vía revert del squash; sin migraciones.
