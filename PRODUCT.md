# PRODUCT — Condor App

> Contrato de producto del panel de administración · noviembre de 2026.
> Este documento describe el **qué** y para **quién**; las decisiones visuales viven en [DESIGN.md](DESIGN.md).

## Qué es

Condor App es una plataforma SaaS multiempresa para operaciones de empresas colombianas: presencia web, comercio, catálogo, inventario, pedidos, talento humano, cumplimiento, documentos y finanzas. Cada empresa y sus usuarios operan dentro del contexto autorizado de tenant, razón social y sede. El panel es una herramienta de trabajo, no un sitio promocional.

## Personas y trabajos

| Perfil | Tarea frecuente | Necesidad |
| --- | --- | --- |
| Administrador de empresa | Revisar el estado del negocio y delegar trabajo | Encontrar pendientes importantes sin perder contexto |
| Operaciones / inventario | Consultar productos, stock y movimientos | Filtrar, comparar registros y corregir errores |
| Comercial | Gestionar pedidos, cobros y clientes | Estados claros y acciones reversibles |
| Talento humano y cumplimiento | Registrar novedades y seguir pendientes | Privacidad, trazabilidad y control de permisos |
| Superadministrador de plataforma | Observar empresas y salud del SaaS | Separación estricta de empresas y acciones autorizadas |

## Principios irrenunciables

1. **Una tarea principal por pantalla.** La navegación agrupa módulos; la página debe explicar dónde se está y qué se puede hacer.
2. **Información real antes que decoración.** Los indicadores provienen de APIs reales con contexto de tenant/sede; una demostración debe anunciarse claramente y jamás mezclarse con producción.
3. **Operabilidad accesible.** Teclado, lector de pantalla, foco visible, semántica nativa, alto contraste y movimiento reducible.
4. **Seguridad contextual.** Permisos de backend y CSRF intactos; ninguna decisión crítica depende solo de componentes React.
5. **Estados explícitos.** Carga, vacío, error, permiso denegado y éxito muestran mensajes operativos sin simular persistencia.
6. **Evolución incremental.** Mantener el contrato funcional existente y ofrecer patrones reutilizables; el rediseño de cada módulo tiene tareas independientes.

## Qué construye el issue #1644

Documentación, tokens, shell refinado y un kit de referencia con cabecera, tarjetas de métricas, filtros, tabla de registros, indicadores de estado, panel de detalle y formulario lateral.

La ruta **local** `/admin#design-preview` muestra, únicamente durante `vite dev`, tres vistas con contenido sintético. No emite peticiones HTTP ni guarda información. En la compilación de producción, `import.meta.env.DEV` impide el acceso a esta vista. Es una guía visual para los desarrolladores, **no** un módulo de personal real.

## Límites de entrega

No introduce nuevas API, permisos, datos de clientes, proveedores, contenido público, CDN, fuentes o recursos remotos; no cambia planes de precios, almacenamiento, modelo de negocio ni fases operativas. Los componentes de referencia no sustituyen las pantallas reales y no cambian la autoridad del servidor.

## Éxito verificable

- Interfaces con estructura consistente y navegación sin pérdida de contexto.
- Contraste de texto WCAG 2.2 AA: al menos 4.5:1 para texto normal, 3:1 para texto grande y componentes no textuales pertinentes.
- Tabulación y activación con Enter/Espacio; `<dialog>` nativo devuelve el foco al disparador.
- Estado oscuro compatible con el sistema, sin suprimir el foco y sin animación obligatoria.
- `CI Factory v1 / Validar` terminal en verde sobre el commit exacto, además de calidad y revisión final requeridas.
