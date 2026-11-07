# Laboratorio de interacciones y métricas de navegador — #1499 / #1830

**Estado:** preparación técnica sintética; **NO VERIFICADO** para release y go-live. No constituye aprobación de presupuesto, certificación WCAG ni telemetría de campo.

## Objetivo y límites

Extiende la cobertura de la hija #1819 (matriz Chrome/Playwright WebKit y auditoría axe pública). Este CLI ejecuta **Chrome headless** en un árbol aislado y servido exclusivamente en localhost, sobre los flujos públicos `/`, `/producto` y `/admin/login` en escritorio 1440×900 y móvil 390×844. No inicia sesión, no accede a clientes, tenant ni plataforma; no lee textos de formularios ni datos reales.

Registra LCP y CLS observados por `PerformanceObserver`, soporte de Event Timing, cantidad de eventos con `interactionId` y máximo de duración observado; **ese máximo no es INP** y no se utiliza para acreditarlo. Recorre 10 veces `Tab` por pantalla y registra tipos de elementos enfocados (no contenidos). Este recorte **no demuestra** orden de foco íntegro, trampas de teclado, lector de pantalla ni accesibilidad WCAG 2.2 AA integral.

Los objetivos técnicos propuestos en `docs/salida-rendimiento-a11y.md` son LCP p75 ≤2500 ms, INP p75 ≤200 ms y CLS p75 ≤0,10. Aquí no se obtiene p75 estadísticamente representativo: **LCP, CLS e INP de campo = NO VERIFICADO**, incluso si los observadores proporcionan muestras locales.

## Reproducción exclusivamente offline

Sobre un checkout limpio y verificable del SHA exacto, levantar Symfony **APP_ENV=test** con SQLite sintético (nunca conectar Hostinger, producción, secretos o datos reales) en `127.0.0.1:18777`, con CSS y JS servidos correctamente. Utilizar Playwright y Chrome ya declarados como dependencias de desarrollo gratuitas.

```sh
node scripts/qa1499-interactions-lab.cjs --self-test
python3 -m unittest tests.test_qa1499_interactions_lab -v
export QA1499_BASE_URL=http://127.0.0.1:18777
export QA1499_ACK_SYNTHETIC_TEST_ONLY=1
export QA1499_HEAD_SHA="$(git rev-parse HEAD)"
umask 077
mkdir -p "$HOME/.qa1499-reports"
chmod 700 "$HOME/.qa1499-reports"
node scripts/qa1499-interactions-lab.cjs > "$HOME/.qa1499-reports/qa1499-interactions-$(git rev-parse --short HEAD).json"
```

Las peticiones a otro origen se abortan, URLs HTTPS, externas, con usuario/contraseña, query o fragmento son rechazadas **antes** de cargar el navegador. Los errores del producto no se imprimen en detalle para evitar posibles secretos o PII. El CLI **no escribe archivos**: emite un único JSON sanitizado por stdout. El operador elige una carpeta privada con permisos 0700 y redirige la salida; no se versiona el recibo. El SHA en JSON es **declarado**: quien sirve el árbol debe demostrar externamente su correspondencia.

## Alcance que sigue NO VERIFICADO

- INP canónico y percentiles de campo representativos (no estimarlos con una sola prueba sintética).
- Login sintético autorizado y flujos tenant/plataforma, estados protegidos y RBAC.
- Safari aplicación nativa, lectores de pantalla y teclado completo.
- Matriz comercial objetivo y presupuestos finales aprobados expresamente por el dueño.
- Gate de rendimiento estático de `public/marketing.css` reclamado por #1425, si sigue fuera de presupuesto.
- Producción, observación real, privacidad, recuperación, go-live, acreditación de los cuatro criterios finales de #1499.

## Riesgo y reversión

Instrumento CLI manual, sin importación al runtime productivo ni CI automático contra live. Si faltan datos o soporte de Event Timing, registrar nulos/NO VERIFICADO, nunca inventar resultados. Revertir el squash elimina estos tres archivos y no modifica despliegue, credenciales ni usuarios.

## Ejecución local observada (no aprobación de release)

**Fecha UTC:** 2026-11-07 10:37:54. **Árbol limpio:** `Condor/main@b92dc9e5070017713c36a015fc802852c82ce083` (confirmado por `git rev-parse HEAD` en clon temporal). **Navegador:** Chrome 156.0.8078.80 con Playwright; servidor Symfony `APP_ENV=test`, `APP_DEBUG=0`, SQLite sintético; localhost `127.0.0.1:18777`; recursos externos bloqueados en el contexto. **Recibo efímero de la primera corrida:** `/tmp/qa1499-interactions-local1830.json`, sin textos DOM, correos, tokens ni datos de clientes. El CLI corregido emite el mismo formato sanitizado **solo por stdout**; los nuevos recibos deben guardarse bajo una carpeta privada 0700 y no se versionan.

| Perfil | Ruta | HTTP | LCP aislado (ms) | CLS observado | Event Timing | Muestras de eventos | `Tab` con foco |
| --- | --- | ---: | ---: | ---: | --- | ---: | --- |
| Escritorio 1440×900 | `/` | 200 | 136 | 0 | soportado | 20 | sí |
| Escritorio 1440×900 | `/producto` | 200 | 284 | 0,0168 | soportado | 13 | sí |
| Escritorio 1440×900 | `/admin/login` | 200 | 52 | 0 | soportado | 11 | sí |
| Móvil 390×844 | `/` | 200 | 588 | 0 | soportado | 20 | sí |
| Móvil 390×844 | `/producto` | 200 | 552 | 0 | soportado | 7 | sí |
| Móvil 390×844 | `/admin/login` | 200 | 144 | 0 | soportado | 13 | sí |

Resultado del CLI: `cases=6`, `failed=0`, `keyboard_cases_with_focus=6`, `lcp_samples=6`, `event_timing_supported_cases=6`, `inp_p75_verified=false`, `field_vitals_verified=false`, `release_approved=false`.

**Límite metodológico:** prueba sobre localhost sin simulación estadísticamente representativa de red/CPU ni usuarios reales, solo 10 pulsaciones `Tab` por vista. Las muestras de Event Timing no son INP canónico, la lectura LCP no sustituye p75 y la presencia de foco no certifica un recorrido completo. Nada de ello completa los cuatro AC de #1499 ni permite activar live.
