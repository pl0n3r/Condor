# Condor — Roadmap canónico

> **Fuente única del estado vivo del proyecto.**
>
> Este archivo condensa el panorama completo de Condor y debe permitir retomar el proyecto sin depender de conversaciones anteriores.
> Toda decisión durable, cambio de dirección, prioridad, hallazgo importante, estado de implementación o siguiente paso debe reflejarse aquí.

## 0. Regla de gobierno

`ROADMAP.md` es la fuente canónica para **qué es Condor, dónde está, qué se decidió, qué falta y qué sigue**.

Reglas:

- actualizar este archivo en toda PR que cambie de forma material el producto, arquitectura, infraestructura, seguridad, calidad, UX, prioridades o estado del roadmap;
- no mantener roadmaps paralelos en README, Issues, chats o documentos sueltos;
- GitHub Issues representan unidades ejecutables de trabajo, pero el panorama consolidado vive aquí;
- `AGENTS.md` define **cómo trabajar**; `ROADMAP.md` define **qué estamos construyendo y en qué estado está**;
- `README.md` presenta el proyecto de forma breve y visual, sin competir con este documento;
- las decisiones sustituidas deben actualizarse aquí en lugar de acumular contradicciones;
- mantener un panorama suficientemente completo para que un agente nuevo pueda leerlo y continuar desde el estado real del repositorio;
- antes de abrir trabajo nuevo, contrastar este roadmap con `main`, PRs abiertos, CI y Issues para evitar duplicados;
- después de cada merge relevante, condensar el resultado aquí.

## 1. Identidad del proyecto

| Campo | Valor |
|---|---|
| Proyecto | **Condor** |
| Repositorio | `pl0n3r/Condor` |
| Rama canónica | `main` |
| Tipo | SaaS de gestión corporativa |
| Estado | Arranque / definición inicial |
| Mercado principal | Colombia |
| Idioma principal | Español (Colombia, `es-CO`) |
| Infraestructura base | Heredada conceptualmente de BRVTAL, sin copiar lógica de negocio ni deuda histórica |

## 2. Principios de desarrollo

Condor adopta desde el inicio las prácticas maduras utilizadas en BRVTAL:

- branch → implementación → pruebas dirigidas → PR → CI → Sonar/CodeRabbit → correcciones → squash merge → validación del SHA exacto de `main`;
- paralelización por defecto para trabajo independiente;
- merges a `main` serializados;
- CI rápido, selectivo y paralelo;
- pruebas de contrato, integración y Playwright E2E cuando corresponda;
- preferencia por pruebas de comportamiento sobre assertions de texto fuente;
- usuario E2E aislado para flujos autenticados cuando exista autenticación;
- seguridad desde el diseño;
- ningún secreto en el repositorio;
- ninguna migración destructiva de producción ejecutada automáticamente;
- CI verde no equivale a validación en producción;
- documentación suficiente para retomar el proyecto sin memoria de chat;
- decisiones rutinarias técnicas se resuelven autónomamente desde el contexto del repositorio;
- español como idioma predeterminado de producto, documentación y colaboración.

## 3. Infraestructura objetivo

Base inicial, sujeta a ajuste si las necesidades reales del producto lo requieren:

- GitHub como fuente de código y colaboración;
- GitHub Actions para CI;
- SonarCloud para análisis estático/calidad;
- CodeRabbit para revisión automática;
- Hostinger shared hosting como destino de producción;
- PHP 8.5;
- MariaDB / MySQL-compatible;
- HTML + CSS + JavaScript con dependencias contenidas;
- Playwright para pruebas de navegador;
- despliegue desde `main` hacia Hostinger;
- separación estricta entre validación de código, observación de despliegue y validación real de producción.

## 4. Arquitectura de producto

**Pendiente de definición.**

Este bloque debe condensar progresivamente:

- usuarios y roles;
- dominios funcionales;
- modelo de datos;
- módulos;
- navegación;
- permisos;
- integraciones;
- límites de arquitectura;
- contratos públicos/internos;
- requisitos de multi-tenancy si aplica.

No se copiará la arquitectura funcional de BRVTAL salvo patrones genéricos que sean apropiados para Condor.

## 5. Estado actual

### Completado

- [x] Proyecto nombrado **Condor**.
- [x] Repositorio oficial creado: `pl0n3r/Condor`.
- [x] `main` definida como rama canónica.
- [x] Decisión: reutilizar la filosofía de infraestructura de BRVTAL.
- [x] Decisión: reutilizar las mismas prácticas maduras de ingeniería.
- [x] Decisión: `ROADMAP.md` será el lugar canónico donde se condensa todo el estado del proyecto.
- [x] Decisión: Condor está orientado inicialmente al público colombiano y usa español de Colombia como idioma principal.
- [x] Decisión: toda la colaboración en GitHub se realizará en español siempre que sea técnicamente viable.

### En curso

- [ ] Definir el alcance funcional de Condor.
- [ ] Diseñar la arquitectura inicial.
- [ ] Crear bootstrap técnico.
- [ ] Crear `AGENTS.md` con protocolo operativo específico de Condor.
- [ ] Crear CI inicial.
- [ ] Configurar SonarCloud.
- [ ] Configurar CodeRabbit.
- [ ] Definir estrategia de entornos y despliegue en Hostinger.
- [ ] Crear baseline de seguridad y testing.

### Pendiente / backlog estratégico

Se irá consolidando aquí a medida que aparezcan requisitos. Los Issues deberán mapearse a este panorama, no sustituirlo.

## 6. Decisiones durables

### D-001 — BRVTAL como referencia de infraestructura, no como código base

Condor reutiliza prácticas, automatización, criterios de calidad, disciplina de CI/CD y patrones generales probados en BRVTAL.

No se copiarán automáticamente:

- módulos de DISCADMIN;
- reglas de negocio;
- identidad visual;
- rutas;
- esquema de base de datos;
- deuda técnica;
- decisiones específicas del dominio musical.

### D-002 — Roadmap como memoria operativa del proyecto

`ROADMAP.md` es el registro consolidado del estado de Condor.

Los demás artefactos tienen responsabilidades distintas:

- **ROADMAP.md:** qué existe, qué se decidió, qué falta, prioridades, riesgos y próximos pasos;
- **AGENTS.md:** cómo deben trabajar humanos/agentes sobre el repositorio;
- **README.md:** presentación breve y visual;
- **Issues:** trabajo ejecutable y trazable;
- **PRs:** cambios concretos y evidencia de validación;
- **docs/**: documentación especializada que sea demasiado extensa para el roadmap.

### D-003 — Español de Colombia como idioma canónico

Condor está pensado inicialmente para usuarios en Colombia. El idioma predeterminado es **español de Colombia (`es-CO`)**.

La regla se aplica, siempre que técnicamente sea viable, a:

- interfaz de usuario, navegación, formularios, validaciones, errores, estados vacíos, ayudas, accesibilidad y mensajes del sistema;
- correos, notificaciones, textos transaccionales y contenido generado por la aplicación;
- documentación del repositorio;
- títulos, descripciones y comentarios de Issues;
- títulos, descripciones, revisiones, respuestas y comentarios de Pull Requests;
- mensajes de commit;
- nombres visibles de workflows, jobs y pasos de GitHub Actions;
- Releases, notas de versión, Projects, milestones y labels;
- comentarios de código cuando sean necesarios;
- textos de pruebas y fixtures visibles para personas.

Convenciones técnicas que deban conservarse por compatibilidad, interoperabilidad o claridad pueden permanecer en inglés: nombres de librerías y APIs, comandos, palabras reservadas, protocolos, formatos estándar, nombres de paquetes, claves externas, identificadores de terceros y términos técnicos cuya traducción introduzca ambigüedad.

Para ramas y nombres internos propios del proyecto, preferir español claro y ASCII cuando sea práctico, por ejemplo `feature/autenticacion`, `fix/permisos` o `docs/roadmap`.

Los formatos locales deben partir de Colombia cuando no exista otro requisito: idioma `es-CO`, moneda COP, fechas/horas comprensibles para usuarios colombianos y textos redactados de forma natural para este mercado.

## 7. Riesgos y restricciones

- evitar sobrearquitectura antes de conocer el alcance funcional;
- evitar copiar componentes de BRVTAL que no tengan sentido para Condor;
- mantener compatibilidad con el hosting elegido mientras no se decida otra plataforma;
- diseñar seguridad, permisos y aislamiento de datos antes de exponer flujos sensibles;
- evitar que Issues, README y chats diverjan del estado condensado aquí;
- evitar anglicismos innecesarios en superficies visibles y documentación sin traducir términos técnicos cuya traducción perjudique precisión o compatibilidad.

## 8. Próximos pasos

1. Definir claramente qué problema resuelve Condor y para quién.
2. Identificar usuarios, roles y flujos principales.
3. Diseñar arquitectura mínima viable.
4. Crear bootstrap técnico e infraestructura CI.
5. Crear primer vertical slice funcional.
6. Añadir seguridad, integración y E2E desde el inicio.
7. Mantener este roadmap actualizado en cada cambio material.

---

**Regla final:** si una decisión o avance es suficientemente importante como para afectar cómo se continúa Condor en una sesión futura, debe quedar condensado aquí.
