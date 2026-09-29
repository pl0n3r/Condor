# Condor App — Snapshot operativo · Home comercial V 0.1.77

> **Candidato objetivo:** V0.1.77 · Issue #329 · propuesta de valor y narrativa comercial del home.
>
> **Producción validada antes del cambio:** V0.1.76 · `main@1fae3cd913f07727bf8e6dca489214bd6f12d9e7` · release/tag exactos, Deploy Observer y CI exact-main en success; `/health` con esquema al día.

Condor continúa en construcción. V0.1.77 convierte la portada pública en una superficie comercial que explica cómo la plataforma conecta la operación real de una empresa sin inventar capacidades ni publicar precios.

## Alcance
- hero “Tu empresa, conectada” y CTA principal “Solicitar una demo”;
- narrativa SSR/Twig de inventario, ventas, producción, compras, clientes, e-commerce y reportes;
- flujo operativo, multiempresa, B2B/B2C, Colombia-first/global-ready, Factory y seguridad con claims acotados;
- sistema visual sobrio/premium, responsive 360 px+, foco visible, semántica y navegación por teclado;
- metadata, canonical y versión visible;
- regresión automatizada del contrato comercial del home.

## Límites
- no se añaden formularios, proveedores, trackers, datos personales ni un canal comercial ficticio;
- no se publican precios;
- no se modifican esquema, dependencias, tenant/storefront routing, autenticación ni APIs;
- visuales del home son estructura y tipografía del producto; no se publica un dashboard ficticio ni datos reales de clientes.

## Evidencia base
- #330 cerró la identidad V0.1.76 y dejó producción exact-main GREEN.
- #329 conserva el contrato de #65 y formaliza la siguiente evolución comercial crítica.
- `tests/test_home_commercial_narrative.py` fija AC-01..AC-05.
- El gate `Pruebas de contrato e integración` cubre AC-06.
