# Condor AI — frontera de handoff sensible v1

Este contrato describe únicamente la entrega de una solicitud sensible minimizada a revisión humana para `identity.permission.change`.

La revisión humana no es una aprobación.
No existe una ruta de ejecución posterior en este slice.
No se registra ningún handler para la acción sensible.
No se consulta ni escribe base de datos ni red.
No se conecta ningún proveedor o modelo.
No se usan datos reales ni PII.
No activa despliegue ni go-live.

## Contrato de salida

El core conserva el handoff canónico y añade `sensitive_request` con solo cuatro referencias opacas: `tenant_ref`, `tool_ref`, `request_ref` y `evidence_ref`.

La composición falla cerrado si la identidad no coincide, si las referencias no son canónicas, si la tool no es la sensible esperada o si la decisión deja de ser `denied / sensitive_requires_human`.

## Invariantes

La ruta sensible termina antes de cualquier resolución del registry, validación de inputs del handler, claim de replay o invocación de tool. Las rutas `read_only` y `reversible_write` conservan su semántica existente.
