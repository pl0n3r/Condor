# Condor #1493 — simulación FinOps offline (hoja #1823)

**Estado: PREPARACIÓN SINTÉTICA, NO_AUTORIZA_GO_LIVE.** El gate económico [#1493](https://github.com/pl0n3r/Condor/issues/1493) permanece **DEL_DUEÑO** (`factory-human-gate`, `safe_default=B`). Sin voto auténtico `/decidir A` no existe presupuesto mensual aprobado, ningún límite real por proveedor, destinatario principal/respaldo autorizado ni prueba real de entrega de alertas. Se aplica **NO_COMPRAR / NO_APLICAR_CAMBIOS**. Este laboratorio no interactúa con cuentas, proveedores ni producción.

## Matriz de umbrales

Los ejemplos usan exclusivamente un límite **sintético** de 10.000 unidades enteras menores en una moneda asignada, sin relación con tarifas efectivas, facturas ni con el techo COP 60.000 ilustrativo y no aprobado de `docs/salida-costos-limites.md`.

| Cruce anterior → actual | Candidato de aviso | Entrega |
| --- | --- | --- |
| 0 → 49 % | ninguno | `NOT_SENT` |
| 49 → 50 % | 50 % | `NOT_SENT` |
| 50 → 79 % | ninguno | `NOT_SENT` |
| 79 → 80 % | 80 % | `NOT_SENT` |
| 80 → 99 % | ninguno | `NOT_SENT` |
| 99 → 100 % | 100 % | `NOT_SENT` |
| 100 → 101 % | ninguno (sin alerta duplicada) | `NOT_SENT` |
| 0 → 101 % | 50, 80, 100 % | `NOT_SENT` |

Matriz sintética **49/50/79/80/99/100/101** con umbrales **50/80/100**. El cálculo usa `previous_minor*100 < threshold*limit_minor <= current_minor*100`, sin errores de redondeo. Se generan candidatos por cada proveedor `SIM_*` y por total sintético, pero **nunca se envían**. Gasto descendente, moneda mezclada, moneda distinta de COP o USD, cifras negativas, límites cero, `bool`, proveedores no sintéticos, repetidos, formatos ambiguos y campos como emails/tokens se rechazan fail-closed. COP y USD deben ensayarse en simulaciones separadas: no inventar TRM, facturar ni sumar divisas.

## Ejecución reproducible y recibo

Solo Python estándar, sin imports de red/proveedores ni configuraciones de nube. El CLI acepta únicamente `--self-test` o `--simulate`; este último lee JSON sintético de stdin y **no escribe archivos ni envía alertas**. El estado de salida es `SYNTHETIC_ONLY`, `budget_authorized=false`, `delivery=NOT_SENT`, `purchasing=NO_COMPRAR`, `operation=NO_APLICAR_CAMBIOS`.

```sh
python3 scripts/finops_1493_offline.py --self-test
printf '%s' '{"synthetic":true,"lines":[{"provider":"SIM_ALPHA","currency":"COP","limit_minor":10000,"previous_minor":4900,"current_minor":5000}]}' | python3 scripts/finops_1493_offline.py --simulate
python3 -m unittest -v tests.test_finops_1493_offline
```

**Recibo esperado:** candidatos 50 % de `SIM_ALPHA` y `SIM_TOTAL`, siempre `NOT_SENT`. Test de entrada desconocida o sensible retorna error y no imprime el dato proporcionado. Ninguna salida constituye aprobación presupuestaria ni prueba de envío.

## Runbook de excedentes (propuesta, no automatización)

1. **50 %:** candidato informativo de revisión de consumo sintético; no enviar hasta destinatarios aprobados.
2. **80 %:** candidato para revisar gasto opcional y comunicar al dueño; sin modificar planes, proveedores ni contratos.
3. **100 %:** candidato urgente para decisión humana; **NO** apagar automáticamente servicios esenciales, eliminar datos, revocar cuentas ni suprimir backups.
4. **Excedente:** operador autorizado debe distinguir gasto confirmado, gasto eventual y cargos pendientes; el dueño decide mantener o cambiar topes. Toda suspensión real requiere otra autorización, evaluación de continuidad, **backup** restaurable y plan de **reversión**. La herramienta nunca opera ese proceso.

**Pendiente DEL_DUEÑO:** aprobar monto por moneda, techo mensual total, topes por proveedor, impuestos, sobreuso, nombres de destinatarios primarios y de respaldo por canal privado; configurar alertas efectivas 50/80/100 en proveedores autorizados; acreditar **prueba real de entrega** y runbook operativo validado. Hasta entonces #1493 sigue abierto y NO_AUTORIZA_GO_LIVE. Fusionar esta herramienta offline, si procede tras V3/CI/CodeRabbit FINAL, **no** completa esa puerta humana.
