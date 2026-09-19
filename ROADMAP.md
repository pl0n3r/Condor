# Cóndor — Roadmap

> Vista ejecutiva del avance del proyecto.  
> Este documento está pensado para que cualquier persona pueda entender **qué se ha hecho, qué se está haciendo, qué sigue y si existe algún bloqueo**, sin entrar en detalles técnicos.

**Última actualización:** 19 de septiembre de 2026  
**Estado general:** 🟡 Arranque del proyecto  
**Mercado inicial:** Colombia

---

## Vista general

| Frente | Estado | Avance actual |
|---|---|---|
| Definición del producto | 🟡 En curso | Alcance funcional por definir |
| Arquitectura | ⚪ Pendiente | Se diseñará después de cerrar el alcance inicial |
| Infraestructura de desarrollo | 🟡 En curso | Se adoptarán las prácticas maduras de BRVTAL |
| Calidad y pruebas | 🟡 En curso | SonarCloud, CodeRabbit, CI y E2E previstos desde el inicio |
| Seguridad | ⚪ Pendiente | Baseline por diseñar junto con la arquitectura |
| Experiencia de usuario | ⚪ Pendiente | Se definirá con los primeros flujos del producto |
| Despliegue | ⚪ Pendiente | Hostinger como base inicial; flujo aún por configurar |

**Leyenda:** 🟢 Completado · 🟡 En curso · 🔴 Bloqueado · ⚪ Pendiente

## Lo que ya está hecho

- ✅ Proyecto creado con el nombre **Cóndor**.
- ✅ Repositorio oficial definido: `pl0n3r/Condor`.
- ✅ Rama principal: `main`.
- ✅ Se decidió reutilizar la experiencia de infraestructura y las prácticas de ingeniería maduras de BRVTAL, sin copiar su lógica de negocio ni su deuda técnica.
- ✅ Español de Colombia (`es-CO`) definido como idioma principal del producto y de la colaboración en GitHub.
- ✅ Este `ROADMAP.md` queda definido como la vista ejecutiva del avance del proyecto.
- ✅ Las especificaciones se separaron del roadmap para mantener esta vista limpia.

## En qué estamos trabajando

1. **Definición del producto**
   - precisar qué problema resuelve Cóndor;
   - identificar usuarios y roles;
   - definir los flujos principales.

2. **Base técnica**
   - preparar el arranque del proyecto;
   - crear las reglas operativas para agentes y desarrollo;
   - configurar CI, SonarCloud y CodeRabbit;
   - preparar pruebas automatizadas desde el inicio.

3. **Arquitectura**
   - se diseñará una vez esté suficientemente claro el alcance inicial;
   - se evitará sobrearquitectura prematura.

## Qué sigue

- Definir el alcance funcional inicial.
- Diseñar la arquitectura mínima viable.
- Crear la base técnica del proyecto.
- Construir el primer flujo funcional completo.
- Incorporar autenticación, seguridad, pruebas e integración según lo requiera ese primer flujo.
- Configurar el despliegue y su observabilidad.

## Bloqueos

**No hay bloqueos activos.**

## Cómo se mantiene este roadmap

Este archivo es un **tablero de progreso**, no un documento de especificaciones.

- cada cambio material debe reflejar aquí su impacto en el avance;
- cada PR relevante debe actualizar el roadmap antes de fusionarse si cambia el estado de algún frente;
- los detalles técnicos, reglas y decisiones viven en [ESPECIFICACIONES.md](ESPECIFICACIONES.md);
- los Issues representan trabajo ejecutable, pero esta página muestra el panorama consolidado;
- cuando el historial crezca demasiado, el trabajo terminado se moverá a `docs/roadmap-historico/` y esta vista conservará solo lo necesario para entender el estado actual;
- el roadmap debe priorizar claridad para negocio y evitar ruido técnico.

---

### Vista rápida

**Ahora:** definición + base técnica.  
**Después:** arquitectura mínima + primer flujo funcional.  
**Bloqueos:** ninguno.
