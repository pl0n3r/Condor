# Cóndor — Especificaciones y decisiones

> Documento de referencia para las reglas, decisiones durables, arquitectura y especificaciones del proyecto.  
> A diferencia de `ROADMAP.md`, este archivo puede contener detalle técnico y funcional.

## 1. Identidad

| Campo | Valor |
|---|---|
| Proyecto | **Cóndor** |
| Repositorio | `pl0n3r/Condor` |
| Rama canónica | `main` |
| Tipo | SaaS de gestión corporativa |
| Mercado inicial | Colombia |
| Idioma principal | Español de Colombia (`es-CO`) |

## 2. Regla de idioma

Siempre que sea técnicamente viable, se utilizará español en:

- interfaz y contenido visible para usuarios;
- documentación;
- Issues, Pull Requests, revisiones y comentarios;
- mensajes de commit;
- Releases, milestones, labels y Projects;
- nombres visibles de workflows, jobs y pasos;
- mensajes de pruebas, errores y validaciones destinados a personas.

Se conservarán en inglés los elementos cuyo nombre sea parte de una interfaz técnica o estándar: APIs, librerías, comandos, palabras reservadas, protocolos, paquetes, claves de terceros e identificadores donde traducirlos genere ambigüedad o problemas de compatibilidad.

Los formatos locales partirán de Colombia cuando no exista otro requisito: `es-CO`, COP y formatos de fecha/hora comprensibles para usuarios colombianos.

## 3. Principios de ingeniería

Cóndor adopta las prácticas maduras aprendidas en BRVTAL, sin copiar su lógica de negocio:

- branch → implementación → pruebas dirigidas → PR → CI → Sonar/CodeRabbit → correcciones → squash merge → validación del SHA exacto de `main`;
- paralelización por defecto del trabajo independiente;
- merges a `main` serializados;
- CI selectivo y paralelo;
- pruebas de contrato, integración y Playwright E2E según corresponda;
- preferencia por pruebas de comportamiento;
- usuario E2E aislado cuando existan flujos autenticados;
- seguridad desde el diseño;
- secretos fuera del repositorio;
- ninguna migración destructiva de producción ejecutada automáticamente;
- CI verde no equivale a validación real en producción.

## 4. Infraestructura objetivo inicial

Sujeta a ajuste según las necesidades reales del producto:

- GitHub;
- GitHub Actions;
- SonarCloud;
- CodeRabbit;
- Hostinger shared hosting;
- PHP 8.5;
- MariaDB / MySQL-compatible;
- HTML + CSS + JavaScript con dependencias contenidas;
- Playwright;
- despliegue desde `main` hacia Hostinger;
- separación entre validación de código, observación del despliegue y validación de producción.

## 5. Arquitectura funcional

Pendiente de definición.

Aquí se documentarán, cuando se definan:

- usuarios y roles;
- permisos;
- dominios funcionales;
- módulos;
- navegación;
- modelo de datos;
- integraciones;
- multi-tenancy si aplica;
- contratos internos y públicos;
- límites arquitectónicos.

## 6. Decisiones durables

### D-001 — BRVTAL es referencia de prácticas, no código base

Cóndor reutiliza aprendizajes de infraestructura, automatización, calidad, seguridad y flujo de entrega de BRVTAL.

No se copiarán automáticamente módulos, esquemas de datos, rutas, identidad visual, reglas de negocio ni decisiones específicas del dominio musical.

### D-002 — Separación entre avance y especificación

- `ROADMAP.md`: vista ejecutiva del progreso.
- `ESPECIFICACIONES.md`: reglas, decisiones y detalle funcional/técnico.
- Issues: unidades de trabajo.
- PRs: cambios y evidencia.
- `docs/roadmap-historico/`: archivo de periodos cerrados cuando el roadmap principal crezca demasiado.
- `AGENTS.md`: protocolo operativo de desarrollo cuando sea creado.

### D-003 — Español de Colombia como idioma principal

Cóndor está pensado inicialmente para el público colombiano y utilizará `es-CO` como idioma predeterminado en producto y colaboración, salvo excepciones técnicas justificadas.

## 7. Criterio de actualización

Una decisión debe incorporarse aquí cuando afecte de manera durable cómo se diseña, implementa, prueba, opera o evoluciona Cóndor.

El roadmap no debe convertirse nuevamente en este documento.
