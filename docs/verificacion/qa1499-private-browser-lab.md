# Condor #1891 — laboratorio privado de navegador sintético

## Propósito y alcance

Este laboratorio desarrolla el instrumento reversible para la hoja QA #1499. Ejecuta
ocho escenarios de recorridos privados locales, no una certificación del producto ni una prueba de
producción:

| Rol ficticio | Ruta privada | Motores | Viewports |
| --- | --- | --- | --- |
| tenant_admin | /admin | Chrome estable y Playwright WebKit | 1440×900 y 390×844 |
| platform_owner | /adminpl0n3r | Chrome estable y Playwright WebKit | 1440×900 y 390×844 |

El producto debe estar levantado por separado en HTTP localhost o 127.0.0.1,
con puerto explícito. La respuesta GET /health debe incluir release_sha igual al
SHA de 40 caracteres del checkout ejecutado. Cada UI privada debe renderizar su
marcador SSR real (#condor-admin-root o #condor-platform-root), shell accesible
y encabezado; un login, redirect, denegación o marcador ausente termina como
NO_VERIFICADO.

## Preflight obligatorio

Antes de cargar Playwright, el script requiere:

- QA1499_BASE_URL: HTTP loopback, puerto explícito, sin credenciales, query ni fragmento.
- QA1499_ACK_SYNTHETIC_TEST_ONLY=1: confirmación explícita de entorno sintético.
- QA1499_HEAD_SHA: SHA exacto de 40 hex; git rev-parse HEAD debe coincidir.
- QA1499_SESSION_DIR: directorio temporal absoluto fuera del repositorio/checkout y con permisos privados.
- Dos fixtures efímeros, tenant-admin.json y platform-owner.json, con permisos
  0600 (directorio 0700). No se versionan, no se adjuntan al PR y se eliminan al
  finalizar el operador.

Cada fixture usa este sobre de metadatos (los valores de cookies/localStorage son
sintéticos y permanecen exclusivamente en el archivo temporal):

    {
      "synthetic_only": true,
      "source_sha": "<SHA exacto del checkout>",
      "role": "tenant_admin",
      "storage_state": {
        "cookies": [],
        "origins": [
          { "origin": "http://127.0.0.1:18891", "localStorage": [
            { "name": "synthetic-session", "value": "<valor sintético>" }
          ]}
        ]
      }
    }

El segundo archivo debe declarar role "platform_owner" y corresponder al mismo
origen localhost. Se exige al menos una cookie o entrada de origen para que el
recorrido no se convierta accidentalmente en una visita anónima. Las cookies solo
pueden pertenecer a localhost o 127.0.0.1; el storage_state solo admite el origen
declarado. El operador debe garantizar que las sesiones son ficticias y
descartables, nunca copiar sesiones de personas o ambientes compartidos.

## Aislamiento y modo de ejecución

La sonda usa GET únicamente; bloquea métodos de escritura, service workers,
WebSockets y cualquier origen que no sea exactamente el loopback autorizado.
Los redirects 3xx se rechazan antes de continuar la navegación. No sigue enlaces,
no envía formularios, no introduce datos, no realiza pairing ni hace llamadas a
proveedores. No imprime valores de sesión, cookies, tokens, inputs, texto de página,
HTML, identificadores de usuario ni URLs completas. El recibo sanitizado va a stdout.

Comprobación estática del script:

    node scripts/qa1499-private-browser-lab.cjs --self-test

Ejecución opt-in, solo con aplicación local efímera y fixtures fuera del repo:

    QA1499_BASE_URL=http://127.0.0.1:18891 \
    QA1499_ACK_SYNTHETIC_TEST_ONLY=1 \
    QA1499_HEAD_SHA="$(git rev-parse HEAD)" \
    QA1499_SESSION_DIR=/tmp/qa1499-sessions \
      node scripts/qa1499-private-browser-lab.cjs

Pruebas de contrato:

    python3 -m unittest -v tests.test_qa1499_private_browser_lab

## Interpretación

Un recorrido completo devuelve PARCIAL: prueba solamente que la ruta privada y
su identidad SSR respondieron en un checkout local con sesiones sintéticas ficticias y motores
de navegador disponibles. Si no hay motor, fixture, sesión, identidad de SHA,
respuesta de health o UI privada, devuelve NO_VERIFICADO y exit no cero.

Este laboratorio no usa Safari nativo, no certifica WCAG 2.2 AA, no demuestra
aislamiento multi-tenant con usuarios reales, no acredita métricas de campo ni Web Vitals p75 representativos, no acredita backup/rollback, no valida release readiness y
no autoriza go-live. Las decisiones de salida y los ensayos con datos reales
permanecen en sus puertas humanas específicas.
