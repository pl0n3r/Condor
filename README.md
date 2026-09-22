# Condor App — Snapshot operativo · candidato V 0.1.15

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** entregar el primer catálogo tenant-owned de productos y variantes, administrable por sede y visible en modo solo lectura desde el contexto explícito de plataforma.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> main `3e9bbf9` · config V 0.1.14 ·
  <strong>Candidato:</strong> V 0.1.15 ·
  <strong>Producción observada:</strong> V 0.1.14 · SHA aún no verificable
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **MAIN SINCRONIZADO** | SHA `3e9bbf9f369c2cfd9f5add33d0b8fb56d376f7b5`; incluye V 0.1.14 + hotfix #156 + navegación #158 |
| Candidato actual | 🚧 **V 0.1.15 EN VALIDACIÓN** | Issue #131 / PR #152 |
| Dominio y persistencia | ✅ **IMPLEMENTADOS** | Product + ProductVariant tenant-owned, ULID y migración MariaDB |
| REST branch-scoped | ✅ **IMPLEMENTADO** | permisos `catalog.*`, CSRF, auditoría, 404 cross-tenant y 409 duplicados |
| Admin React | ✅ **IMPLEMENTADO** | CRUD de productos/variantes, responsive y estados reales |
| Super Admin | ✅ **SOLO LECTURA** | catálogo del tenant seleccionado sin impersonación |
| Producción observada | 🚧 **V 0.1.14, SHA NO VERIFICABLE AÚN** | #161 detectó `release_sha=dev`; V 0.1.16 corrige esa evidencia |
| Producción V 0.1.15 | ⏳ **NO VALIDADA** | no se ha desplegado ni migrado producción |

## Qué incorpora V 0.1.15

- Productos y variantes con aislamiento tenant-first y FKs que impiden cruces entre empresas.
- Slug único por tenant y SKU normalizado/único por tenant.
- CRUD REST autorizado por sede con `catalog.view/create/update/delete`.
- Mutaciones protegidas por CSRF, payload estricto, auditoría y soft-delete.
- Interfaz de catálogo dentro del Admin con carga, vacío, error, permisos y responsive.
- Vista explícita de catálogo en el centro de plataforma, deliberadamente solo lectura.
- Contrato REST documentado y regresiones PHP/E2E.
- Cobertura E2E real del propietario de plataforma: cuenta efímera provisionada en CI, navegación Empresas/Staff y selectores accesibles (#159).
- Sin inventario, precios, carrito ni checkout: esos dominios siguen desacoplados para slices posteriores.

## Qué sigue

- Cerrar Quality Gate, CodeRabbit y CI del SHA exacto final de V 0.1.15.
- Integrar solo cuando el candidato esté verde y sin findings válidos.
- Mantener deploy/migración de producción como transición separada y explícitamente autorizada.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** CI verde o merge prueban código, no producción. Solo deploy observado y smoke real permiten declarar **VALIDADO EN PRODUCCIÓN**.
