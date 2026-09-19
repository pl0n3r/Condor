# Histórico auxiliar del roadmap de Condor

Esta carpeta puede almacenar **snapshots o copias auxiliares** de etapas del roadmap canónico para consulta, auditoría o respaldo documental.

## Regla principal

El roadmap canónico vive en el [Issue #1](https://github.com/pl0n3r/Condor/issues/1) y funciona como un **log acumulativo append-only**.

Por lo tanto:

- no se eliminan del Issue #1 tareas, fases, hitos o bloqueos históricos;
- no se mueven entradas fuera del Issue #1 para “limpiarlo”;
- el trabajo completado permanece visible y tachado;
- esta carpeta nunca sustituye el historial canónico;
- hasta, como mínimo, la primera **v1.0.0 madura**, se conserva trazabilidad detallada de todo el trabajo relevante.

## Uso permitido de esta carpeta

Puede utilizarse para:

1. crear snapshots por fecha o release;
2. conservar vistas congeladas de una etapa;
3. facilitar auditorías o revisiones históricas;
4. documentar un volumen cerrado si algún día el roadmap debe continuar en otro Issue por límites prácticos de tamaño.

Si el roadmap necesita dividirse:

- el Issue #1 permanece intacto;
- se crea un Issue/volumen de continuación;
- ambos quedan enlazados;
- ninguna entrada histórica se borra ni se reescribe para compactar.

## Convención de progreso

- ✅ ~~Completado y validado~~
- 🚧 Pendiente / en curso
- ⛔ Bloqueado / dependencia externa
