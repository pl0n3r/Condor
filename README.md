# Condor App — Snapshot operativo · candidato V 0.1.42

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** completar el slice de pedidos compartido por Admin y e-commerce con reservas transaccionales de inventario, aislamiento por sede y defensas contra abuso del checkout anónimo.

<p align="center">
  <strong>Producción GREEN vigente:</strong> V 0.1.41 · `de23a54904fd1342ad110d00f7c995f98f2696ad` ·
  <strong>Candidato:</strong> V 0.1.42 · Issue #183 / PR #184
</p>

## Estado

| Señal | Estado |
| --- | --- |
| Producción vigente | ✅ V 0.1.41 GREEN |
| Candidato serial | 🚧 V 0.1.42 · pedidos/reservas |
| Factory kit | ✅ `pl0n3r/factory@v1` · 1.0.4 |
| Deploy/rollback Factory | ⏭️ #227 · V 0.1.43 |
| Cierre adopción Factory | ⏭️ #228 · V 0.1.44 |

## Qué incorpora V 0.1.42

- dominio único `Order / OrderLine / OrderEvent` para pedido manual y storefront;
- snapshots históricos de precio y estados separados de pedido, pago y fulfillment;
- `InventoryReservation` y `reserved_quantity` separados del on-hand;
- creación idempotente y transaccional, sin oversell cuando backorder no aplica;
- cancelación/liberación y consumo exactamente una vez;
- UI Admin y checkout público sobre el mismo `OrderService`;
- aislamiento de lectura/cancelación/consumo por fuentes de inventario accesibles a la sede;
- rate limit por tenant + IP para checkout anónimo;
- TTL de 30 minutos para reservas e-commerce y comando acotado `app:orders:release-expired`;
- migración expand-compatible; rollback destructivo sigue bloqueado si existen pedidos/reservas reales.

## Seguridad y recuperación

- El servidor sigue siendo autoridad de precio, inventario, fuente y total.
- Usuarios de una sede no pueden leer ni mutar pedidos asociados a fuentes de otra sede.
- El checkout anónimo limita creación masiva de reservas y las reservas abandonadas tienen expiración explícita.
- La activación de un cron productivo para liberar vencidas **no forma parte de este PR**; el proceso CLI queda disponible para la fase de operación aprobada.
- Ningún SQL destructivo se ejecuta automáticamente.

## Validación esperada

- `Backend PHP / MariaDB`;
- `Frontend TypeScript / build`;
- `Playwright Chromium`;
- gates Factory v1, seguridad, privacidad y análisis estático aplicables.

## Qué sigue

1. integrar #183 / PR #184 solo con gates exact-head verdes;
2. validar V 0.1.42 en producción según los cinco puntos GREEN;
3. ejecutar #227 como V 0.1.43;
4. cerrar la adopción Factory con #228 como V 0.1.44;
5. retomar el Roadmap normal.

## Referencias

- [AGENTES.md](./AGENTES.md)
- [ESPECIFICACIONES.md](./ESPECIFICACIONES.md)
- [GLOSARIO.md](./GLOSARIO.md)
- Roadmap canónico: Issue #1
- Épico Factory: Issue #192
- Factory: `pl0n3r/factory@v1`
