# Condor App — Snapshot operativo · Pedidos transaccionales V 0.1.50

> **Candidato:** Issue #183, pedido manual/e-commerce con reserva, liberación y consumo de inventario.

Condor continúa en **construcción**. V0.1.50 recupera sobre el main actual el slice transaccional de pedidos y checkout, preservando la coordinación Factory v2 y la reducción de fan-out integrada en V0.1.49.

## Alcance
- dominio único de pedidos con `Order`, `OrderLine` y `OrderEvent`, snapshots monetarios y estados independientes;
- reservas de inventario persistentes con operaciones idempotentes de reservar, liberar y consumir;
- pedido manual Admin y checkout público sobre el mismo servicio transaccional;
- liberación de reservas expiradas mediante servicio/command dedicado;
- migración aditiva y expand-compatible para pedidos y reservas;
- UI Admin, contratos PHP y cobertura Playwright para retry, cancelación y consumo;
- rate limiting de checkout y validación por tenant/entidad/sede/fuente.

## Seguridad y reversión
El slice conserva aislamiento por tenant y entidad, no ejecuta SQL de producción ni modifica secretos o infraestructura. La migración es aditiva; cualquier despliegue requiere el flujo normal de backup, migración versionada y observación productiva. Revertir este candidato retira la superficie de pedidos sin alterar la telemetría/coordinación integrada en V0.1.49.

## Evidencia base
- `main@ddd7c5376f0d61b14851e4e16f700858d4962c51` · V0.1.49; #188 / PR #253 ya fusionado.
- Trabajo recuperado desde el antecedente histórico #184 y validado de nuevo sobre el main actual.
- El candidato V0.1.50 debe pasar CI exact-head, Política/Privacidad, revisión y gates de coordinación antes de merge.
- Merge/CI verde no equivale automáticamente a producción validada.

## Fuentes de verdad
- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- Roadmap: Issue #1
- Slice actual: #183
