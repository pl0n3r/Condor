# Salida a producción: costos y límites de Condor

**Corte:** 27-oct-2026 · [Issue #984](https://github.com/pl0n3r/Condor/issues/984). **Estado: PREPARACIÓN / NO_AUTORIZA_GO_LIVE.** `datos.yml` conserva `no_real_customer_data=true`. Esto es una estimación externa, NO un contrato, factura, compra ni presupuesto aprobado.

## Propósito y alcance

Documentar hosting, correo, monitoreo y alertas, respaldos y restauración, dominios/DNS/TLS, renovación, costos y límites. `DEL_DUEÑO` significa que solo el propietario aprueba cuentas, gasto, proveedores, renovaciones, pagos, cambios DNS o go-live. Ni siquiera una opción gratuita autoriza activar un servicio.

## Matriz de costos, planes, condiciones y fuentes

| Servicio | Plan de referencia (NO contratado) | Estimación | Facturación / renovación | Fuente | Estado |
| --- | --- | --- | --- | --- | --- |
| Hosting | Hostinger Premium, comprobar primero plan efectivo y compatibilidad PHP/Symfony | COP 29.900/mes renovación; promoción COP 7.900/mes | Equivalente mensual de **48 meses prepagados**, no mensualidad facturada. | [Hostinger](https://www.hostinger.com/co/precios) | `DEL_DUEÑO` |
| Correo | Hostinger Mail Starter, 1 buzón supuesto; comprobar si hosting incluye correo | COP 3.900/mes renovación; promoción COP 1.900/mes | Por buzón, **48 meses prepagados**; evitar doble cobro. | [Hostinger Mail](https://www.hostinger.com/co/correo-corporativo) | `DEL_DUEÑO` |
| Monitoreo y alertas | Workflow `.github/workflows/external-uptime.yml`; opción UptimeRobot Free sin confirmar conexión | COP 0/mes de licencia Free (supuesto) | Free ofrece chequeos a 5 minutos; cobertura/alertas reales no verificadas. | [UptimeRobot](https://uptimerobot.com/pricing/) | `DEL_DUEÑO` |
| Respaldos y restauración | Backup semanal solo si aplica plan Premium; respaldo externo `POR_COTIZAR` | COP 0/mes marginal en plan supuesto; externo `POR_COTIZAR` | **No** demuestra copia vigente ni prueba de restore/RPO/RTO; dependencia [#981](https://github.com/pl0n3r/Condor/issues/981). | [Hostinger](https://www.hostinger.com/co/precios) | `DEL_DUEÑO` |
| Dominios, DNS y TLS | 1 dominio .com.co hipotético; registrador y propiedad de Condor no confirmados | COP 91.900/año renovación = COP 7.658,33/mes amortizado; 1er año COP 65.900 | Renovación anual por dominio; clientes adicionales multiplican N; TLS puede venir incluido. | [Hostinger .com.co](https://www.hostinger.com/co/tld/dominio-com-co) | `DEL_DUEÑO` |
| Correo transaccional opcional | Resend Free: 3.000 correos/mes (100/día), o Pro: 50.000/mes; no activado | USD 0/mes o USD 20/mes | Excedentes y proveedor sujetos a aprobación; no mezclar USD y COP. | [Resend](https://resend.com/pricing) | `DEL_DUEÑO` |
| Monitoreo externo opcional | UptimeRobot Solo, alternativa no contratada | USD 144/año = USD 12/mes amortizado | Facturación anual; no sumar además la licencia Free. | [UptimeRobot](https://uptimerobot.com/pricing/) | `DEL_DUEÑO` |

**Fuente y precisión:** páginas comerciales consultadas 27-oct-2026. Promociones, precios de renovación, TRM, IVA/impuestos, capacidades, cantidad real de clientes, certificados, espacio de backups y cargos por uso pueden variar. Ningún dato prueba facturación actual de Condor. Faltan cotizaciones reales y restauración probada.

## Presupuesto ilustrativo por escenario

- **Base COP hipotética (1 dominio):** `29.900 + 3.900 + 0 + 0 + 91.900 / 12 = COP 41.458,33/mes`; **COP 497.500/año** equivalente a precios de renovación publicados. **Subtotal incompleto**, excluye impuestos, backup externo `POR_COTIZAR`, consumos y soporte.
- Si el correo ya está incluido, restar COP 3.900/mes tras verificarlo. Un dominio .com.co adicional de referencia agrega COP 91.900/año o COP 7.658,33/mes.
- **Alternativas pagas sin tasa inventada:** `COP 41.458,33/mes + USD 32/mes` (USD 20 Resend Pro + USD 12 UptimeRobot Solo amortizado), más backup externo. Formula: `total_COP = 41.458,33 + 32 × TRM_efectiva + impuestos + comisiones + backup + excedentes`.
- **Techo de ejemplo COP 60.000/mes: NO APROBADO.** Solo aplica al subtotal COP; no cubre costos en USD ni `POR_COTIZAR`. Nunca usarlo como evidencia de solvencia o autorización.

## Límites de gasto y alertas

| Umbral | Ejemplo para techo COP 60.000 | Responsable y respuesta |
| --- | --- | --- |
| **70% preventivo** | COP 42.000/mes | Operación avisa y revisa consumo/renovación; `NO_COMPRAR` extras. |
| **90% crítico** | COP 54.000/mes | Dueño decide; detener **altas o upgrades** no autorizados, sin cortar servicio ni datos existentes. |
| **100% o costo desconocido** | COP 60.000/mes o `POR_COTIZAR` | Sin aprobación escrita, no contratar ni generar gasto. |
| Vencimiento | Avisar 30/15/7 días antes del vencimiento contractual real | Dueño aprueba; no subir tarjetas, claves, facturas ni datos de cliente a GitHub. |

Para cargos en USD, manejar umbrales 70% y 90% independientes respecto al techo USD decidido por el dueño. Probar con **datos sintéticos** a 69%, 70%, 89%, 90% y 100%.

## Pasos de operación y autoridad

1. **Ingeniería/operación:** identificar planes, contratos, consumo y renovación en un canal privado autorizado. No conectarse a cuentas ni publicar secretos.
2. **Ingeniería/QA:** contrastar fuentes y solicitar cotización de hosting, correo, monitoreo, almacenamiento externo y DNS/TLS con precio inicial, renovación, prepago, impuestos, sobreuso y cancelación. Planificar restauración con [#981](https://github.com/pl0n3r/Condor/issues/981).
3. **DEL_DUEÑO:** **aprobar gastos, cuentas, medios de pago, proveedores, techo definitivo, dominios por empresa, renovaciones, notificaciones y go-live**. Sin esa decisión: `NO_COMPRAR` y `NO_APLICAR_CAMBIOS`.
4. **QA:** comprobar fuentes, vacíos, escenarios de tarifa/moneda, alertas y CI `Validar` exact-HEAD. CI verde no es `VALIDATED_IN_PRODUCTION`. Gate adicional [#616](https://github.com/pl0n3r/Condor/issues/616).

## Resumen, decisiones y reversión

Siete filas con costos ilustrativos y fuentes; subtotal base COP y alternativa USD sin conversión inventada, umbrales y responsables. Pendiente `DEL_DUEÑO`: aprobación económica, backup externo y evidencia de restore, proveedor, cuentas, términos legales y go-live.

Reversión: revertir squash documental y prueba. No se modifica runtime, hosting, secretos, DNS, datos reales ni producción. **NO_AUTORIZA_GO_LIVE / NO_COMPRAR / NO_APLICAR_CAMBIOS.**
