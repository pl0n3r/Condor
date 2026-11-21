# Condor #1594 — evidencia externa y reconciliación D-041..D-044

> **Corte documental:** 21-nov-2026. **Veredicto:** cobertura externa PARCIAL / UNKNOWN, **NO** `VALIDATED_IN_PRODUCTION`, **NO** autorización de go-live. Este informe es una fotografía trazable, no un certificado operativo ni una solicitud de despliegue.
>
> **Alcance autorizado:** solo el informe de [#1594](https://github.com/pl0n3r/Condor/issues/1594), bajo reserva V3 sobre `docs/verificacion/decisiones-04-evidencia-externa.md`. No modifica `docs/matriz-cobertura.md`, `docs/verificacion/decisiones-04.md`, runtime, base de datos, entornos externos o credenciales.

## 1. Identidad, fuentes y método de verificación

- **Código de referencia:** `Condor/main@2a6509770cd01860d75947fa7b4350d65291b80e`, rama protegida, leído por GitHub el 21-nov-2026. El `datos.yml` de ese corte declara `phase=construccion`, `nothing_live=true` y `no_real_customer_data=true`. Estos son **atributos declarados del repositorio**, no una auditoría de datos de hosting.
- **Línea base de pruebas:** [#1547](https://github.com/pl0n3r/Condor/issues/1547), [PR #1595](https://github.com/pl0n3r/Condor/pull/1595) fusionado, [`docs/verificacion/decisiones-04.md`](decisiones-04.md) y `tests/test_verificacion_decisiones_04.py`. Las pruebas invocan contratos offline/loopback. No prueban navegador autenticado ni producción.
- **Reparación previa:** [#1908](https://github.com/pl0n3r/Condor/issues/1908) y [PR #1909](https://github.com/pl0n3r/Condor/pull/1909) fusionado. [Incidente #1705](https://github.com/pl0n3r/Condor/issues/1705) está **cerrado**, con [recibo del bot](https://github.com/pl0n3r/Condor/issues/1705#issuecomment-6833330323) de **dos observaciones externas `HEALTHY`** a 12-nov-2026 19:10:42 UTC. El dueño [liberó la investigación](https://github.com/pl0n3r/Condor/issues/1705#issuecomment-7065998941) y [desbloqueó #1594](https://github.com/pl0n3r/Condor/issues/1594#issuecomment-7066009895) el 21-nov. El texto antiguo `DEGRADED` en el cuerpo del incidente es histórico y no anula su cierre.
- **Resultado público histórico observado:** [comentario en #1705](https://github.com/pl0n3r/Condor/issues/1705#issuecomment-6828633560) del 12-nov registra `/health` HTTP 200, `schema_up_to_date=true`, y `/post-deploy-status.php` `complete/success`, con release `0.1.200@cbc04a566d75149ccb4224fa43facfacb0796094`. La ruta administrativa anónima devolvió 401 (esperable). **No** hubo entonces sesión administrativa autenticada completa.
- **Observación sobre el SHA de referencia:** [observer automático #44872286995](https://github.com/pl0n3r/Condor/actions/runs/44872286995) terminó `success`, pero el job ejecutó **«Registrar push sin release D-043»**. Según su workflow, eso significa `NO_EVALUADO` para producción; no emitió un recibo terminal.
- **Último uptime consultado en este corte:** [run #44927432236](https://github.com/pl0n3r/Condor/actions/runs/44927432236), `schedule`, HEAD de workflow `2a650977...`, `completed/success`; el job ejecutó **dos muestras** y evaluación de alerta. La propia configuración declara **«Evidencia remota persistida: no»** y elimina los JSON efímeros. Por ello el éxito de Actions no proporciona dos respuestas `HEALTHY` auditables ni una identidad de release exacta de esa ejecución. **Sin artifacts** de este run.
- **Smoke autenticado:** [run #44909247353](https://github.com/pl0n3r/Condor/actions/runs/44909247353), `schedule`, `completed/skipped` en el SHA de referencia; no acredita sesión, RBAC ni recorrido real.
- **CI de código base:** [CI Condor #44872287673](https://github.com/pl0n3r/Condor/actions/runs/44872287673) terminó `success` en `main@2a650977...`, con pruebas de integración, frontend, Playwright y recuperación en entornos descartables. **No es el CI de un futuro PR #1594** y no reemplaza pruebas externas.
- **Lectura pública directa en este ciclo:** se intentó abrir `https://www.condorapp.com.co/health` y `/post-deploy-status.php` de forma anónima; el lector no pudo acceder a esas URLs. Esto es **SIN OBSERVACIÓN**, no una respuesta HTTP de error ni señal de caída. No se usaron sesión, cookies, token, SSH, cuentas, SQL o clientes reales.

### Semántica estricta

| Estado | Qué demuestra | Qué nunca demuestra por sí solo |
| --- | --- | --- |
| `LOCAL_PARTIAL` | Contrato o aserción offline previamente ejecutado | WCAG integral, release desplegada, banco, backup productivo, E2E autenticado |
| `HISTORICO_PARCIAL` | Observación auténtica de una fecha, release y fuente específicos | Salud actual o de otro SHA |
| `UNKNOWN` | No hay evidencia terminal suficiente para la obligación integral | Aprobación, fallo productivo o `CUBIERTO` |
| `SKIPPED` / `NO_EVALUADO` | Trabajo externo no ejecutado/evaluado | Prueba pasada |
| `VALIDATED_IN_PRODUCTION` | Requiere recibo oficial D-043 terminal, versión y SHA, controles aplicables y smoke | No se obtuvo para el corte del presente informe |

## 2. Matriz ID → evidencia externa o brecha verificada (AC-01)

| ID | Estado integral en este corte | Evidencia auténtica y límite |
| --- | --- | --- |
| **D-041-S07** Accesibilidad | `LOCAL_PARTIAL / UNKNOWN` | #1547 prueba headings/labels HTML y negativos; falta auditoría WCAG del shell administrativo real con teclado, lector y viewport |
| **D-041-S08** Estados UI | `LOCAL_PARTIAL / UNKNOWN` | Contrato de estados visibles en Twig; faltan loading, vacío, 403, error y recuperación del admin en navegador autorizado |
| **D-041-S09** Microcopy | `LOCAL_PARTIAL / UNKNOWN` | CTA y navegación pública de #1547; falta revisión completa es-CO en formularios, fallos, permiso denegado y lectores |
| **D-042-S05** Avance Issue→producción | `UNKNOWN` | Hay eventos de GitHub y pruebas de duración CI, pero no una cadena terminal única de instrucción, PR, versión, despliegue y validación |
| **D-043** Ciclo de entrega | `LOCAL_PARTIAL / UNKNOWN` | Reducer y guardas offline de #1547; observer #44872286995 no ejecutó validación de una release |
| **D-043-S01** Estados canónicos | `LOCAL_PARTIAL / UNKNOWN` | Tests distinguen `DEPLOY_OBSERVED` de `VALIDATED_IN_PRODUCTION`; no existe recibo terminal para el SHA de este informe |
| **D-043-S02** Identidad de release | `LOCAL_PARTIAL / HISTORICO_PARCIAL` | Manifest versión/SHA probado offline; `0.1.200@cbc04a5...` observado el 12-nov, no equivale al `main@2a650977...` de este corte |
| **D-043-S03** Compatibilidad Hostinger | `LOCAL_PARTIAL / UNKNOWN` | Test de MIME/JS legacy en observer; sin ensayo completo de compatibilidad del artefacto y entorno de hosting vigente |
| **D-043-S04** Build reproducible | `LOCAL_PARTIAL / UNKNOWN` | CI #44872287673 construyó frontend y verificó reproducibilidad en CI; falta cotejo del build realmente publicado en Hostinger |
| **D-043-S05** Migraciones | `LOCAL_PARTIAL / HISTORICO_PARCIAL` | #1908/#1909 y observación histórica de esquema convergido; sin comprobación privada nueva del inventario Doctrine, restore real ni recibo D-043 terminal de la última release |
| **D-043-S06** Observación del despliegue | `LOCAL_PARTIAL / UNKNOWN` | Observer distingue SHA y emite NO_EVALUADO sin nueva release; #44872286995 no acredita transiciones operativas finales |
| **D-043-S07** Smoke público | `HISTORICO_PARCIAL / UNKNOWN` | /health HTTP 200 y esquema al día el 12-nov según #1705; en el corte actual los GET directos no fueron accesibles desde el lector, por tanto no hay smoke actual exact-release |
| **D-043-S08** Smoke autenticado | `LOCAL_PARTIAL / UNKNOWN` | Tests Symfony sintéticos comprueban permisos; #44909247353 estuvo `skipped` y no se verificó interfaz autenticada en hosting |
| **D-043-S09** Instrucción→producción | `LOCAL_PARTIAL / UNKNOWN` | Test de `elapsed_seconds` mide duración técnica; faltan marcas de tiempo y recibo D-043 enlazados para un recorrido completo real |
| **D-043-S10** Primer release V0.1.0 | `UNKNOWN` | No hay recibo histórico de Hostinger + SHA + versión + smoke recuperado que certifique ese primer corte |
| **D-044** Operación integral | `LOCAL_PARTIAL / UNKNOWN` | Fuentes de health/CI/uptime dan partes del recorrido, no observabilidad, alertas, restore y capacidad integrales |
| **D-044-S01** Observabilidad | `LOCAL_PARTIAL / UNKNOWN` | Job externo #44927432236 ejecutó muestras sin archivarlas; faltan métricas/errores productivos verificables por ventana e identidad |
| **D-044-S02** Alertas | `LOCAL_PARTIAL / HISTORICO_PARCIAL` | Alerta #1705 cerrada mediante recibo de dos HEALTHY; faltan notificación real a receptor, acuse y ejercicio de escalamiento |
| **D-044-S03** Backups y recovery | `LOCAL_PARTIAL / UNKNOWN` | CI #44872287673 probó restore de DB/archivos sintéticos y descartables; no acredita backup/restore productivo ni RPO/RTO del hosting |
| **D-044-S04** Rendimiento | `LOCAL_PARTIAL / UNKNOWN` | Test de ventanas de CI; faltan CWV p75, p95 API, carga y presupuesto observados para la release y entorno objetivo |

**Resultado del inventario:** cada obligación integral tiene fuente concreta, límite y estado `UNKNOWN` donde no hay prueba completa. Ninguna se promueve a `CUBIERTO` por ausencia de error, HTTP 200 histórico, CI verde o nombre de test.

## 3. Negativos, casos límite y separación de autoridad (AC-02)

1. **SHA distinto o versión reutilizada:** un check, URL, backup o smoke de `cbc04a5...` no certifica `2a650977...`; una release debe enlazarse con el hash realmente servido. `main` puede avanzar sin crear release.
2. **HTTP 200 parcial:** `status=ok` o un inicio público accesible no garantizan `schema_up_to_date=true`, autenticación, permisos, páginas internas ni D-043 terminal.
3. **Run verde sin evidencia:** `External uptime` descarta sus samples; `Observar deploy` del HEAD actual registró `NO_EVALUADO`; un `skipped` jamás se transforma en `success` funcional.
4. **Segregación de ambientes:** el E2E con MariaDB descartable y cuentas `*.test` de CI no prueba multi-tenancy, WCAG o restore en hosting. El 401 anónimo del centro de control histórico no equivale a 200 autenticado.
5. **Privacidad/seguridad:** no capturar bodies, PII, JWT, headers de sesión, rutas identificables, backups ni SQL con datos en este informe. Cualquier prueba autenticada requiere identidad sintética permitida y permiso vigente; nunca producir llamadas de escritura real.
6. **Rollback y verificación humana:** no usar `--admin`, editar ruleset, ejecutar migraciones destructivas, marcar flags operativos verdaderos sin prueba ni disparar `workflow_dispatch` D-043 con consentimientos inventados.
7. **Fuente inaccesible:** queda `UNKNOWN` y se conserva origen/timestamp; no se interpreta como sitio caído, rechazo de autenticación o dato silenciosamente valido.
8. **Concurrencia y caducidad:** la V3 y los claims son exclusivos de este archivo; evidencias exact-HEAD pierden aplicabilidad si `main` o la release observada cambian.

## 4. Propuesta separada de reconciliación de matriz (AC-03)

**No editar `docs/matriz-cobertura.md` bajo #1594.** Una hoja de reconciliación posterior puede cruzar, por cada ID, `código → test que falla al revertir → PR fusionado → observación externa (fecha, versión, SHA, ambiente y recibo)`. Las filas `LOCAL_PARTIAL` solo acreditan su subcontrato y las `UNKNOWN` permanecen `NO_VERIFICABLE` integralmente.

| Lote | Evidencia que habilitaría promoción integral | Puerta humana / técnica |
| --- | --- | --- |
| D-041-S07..09 | sesión de prueba autorizada, matriz WCAG, accesibilidad/teclado, estados admin y microcopy con negativos | roles sintéticos, privacidad D-063 y alcance de navegador |
| D-042-S05 / D-043-S01..10 | recibo D-043 `VALIDATED_IN_PRODUCTION` terminal exact-version/SHA, build/deploy/backup cuando aplique, smoke público y autenticado | operador autorizado y checklist humano para transiciones; no activar live |
| D-044-S01..04 | métricas externas por ventana, prueba de alerta/escalamiento, restauración aislada de backup válido y p75/p95 | acceso SRE permitido, datos mínimos, evidencias de recovery no destructivo |

La reparación #1908 y el cierre #1705 **eliminan el veto de readiness histórica que bloqueaba el inicio de #1594**, pero no demuestran las demás filas ni conceden una aprobación de release. La decisión del dueño del 21-nov permite diagnóstico/aditividad con backup verificable, **no** borrados, restore que descarte datos ni go-live.

## 5. Evidencia por criterio y reversión

- **AC-01:** inventario por ID arriba, con fuentes nativas y `UNKNOWN` explícito donde faltan recibos externos. Cumplido como **informe honesto**, no como cierre de todas las brechas.
- **AC-02:** negativos/límites de SHA, seguridad, fuente y estados documentados en §3. No se ejecutaron pruebas productivas nuevas desde esta rama.
- **AC-03:** propuesta de reconciliación en §4 sin modificar la matriz original ni tomar sus claims.
- **AC-04:** `CI Factory v1 / Validar` del **HEAD de este PR** se debe comprobar terminal tras commit/push. El CI de `main` usado como contexto no satisface este AC.
- **Reversión:** revertir el squash de este único documento; no existen migraciones, efectos de UI, escrituras externas ni datos nuevos que restaurar.

**No reclamar:** `VALIDATED_IN_PRODUCTION`, smoke administrativo real, go-live, backup productivo restaurado ni métricas reales hasta obtener los recibos independientes y las aprobaciones aplicables.
