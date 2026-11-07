# M01 RR. HH. · recorrido HTTP transversal S02–S11 (#1799)

**Alcance:** ejecución Symfony `WebTestCase` con dos tenants inventados, dos razones sociales del mismo tenant y sedes distintas. Todas las identidades usan `@example.test`; no se consulta ni persiste información laboral real. Fase `construccion`; la decisión humana #742/B, D-059 y D-063 siguen siendo puertas independientes.

## Reproducción

En el entorno de test con PHP 8.5, dependencias Composer y MariaDB de prueba descartable:

```sh
APP_ENV=test vendor/bin/simple-phpunit tests/php/Http/HrM01CatalogHttpJourneyTest.php
```

El mismo testigo debe pasar en **Pruebas de contrato e integración** y el SHA exacto del PR debe obtener `CI Factory v1 / Validar` en `success`. La ejecución local nunca sustituye los checks canónicos. Archivo: `tests/php/Http/HrM01CatalogHttpJourneyTest.php`.

## Mapa de rutas y aserciones

Todas las rutas tienen prefijo `GET /api/v1/branches/{branchId}/hr/`. Se verifican respuestas HTTP auténticas de Symfony (no mocks del router), `synthetic=true`, `persisted=false`, `juridically_binding=false`, `Cache-Control: private, no-store`, `nosniff` y referencias opacas separadas por ámbito.

| Fila | Segmento listado / detalle | Clave JSON | Evidencia y límite |
| --- | --- | --- | --- |
| M01-S02 | `positions` / `positions/{ref}` | `positions` | LOCAL_PARTIAL: cargos ficticios, no relación laboral |
| M01-S03 | `areas` / `areas/{ref}` | `areas` | LOCAL_PARTIAL: áreas ficticias, no organigrama certificado |
| M01-S04 | `branch-preview` / `branch-preview/{ref}` | `branches` | LOCAL_PARTIAL: sedes proyectadas, sin cambios productivos |
| M01-S05 | `org-chart` / `org-chart/{ref}` | `nodes` | LOCAL_PARTIAL: nodos no oficiales |
| M01-S06 | `changes` / `changes/{ref}` | `changes` | LOCAL_PARTIAL: novedades ficticias |
| M01-S07 | `permits` / `permits/{ref}` | `permits` | LOCAL_PARTIAL: permisos sin efectos legales |
| M01-S08 | `vacations` / `vacations/{ref}` | `vacations` | LOCAL_PARTIAL: vacaciones sin saldo real |
| M01-S09 | `incapacities` / `incapacities/{ref}` | `incapacities` | LOCAL_PARTIAL: incapacidades sin dato médico real |
| M01-S10 | `evaluations` / `evaluations/{ref}` | `evaluations` | LOCAL_PARTIAL: evaluaciones sin ranking vinculante |
| M01-S11 | `employee-portal` / `employee-portal/{ref}` | `items` | LOCAL_PARTIAL: portal de solo lectura ficticio |

**Negativos por cada submódulo:** un usuario sin `users.view` recibe 403; el OWNER recibe 404 al consultar una sede de otro tenant o solicitar en su sede una empresa diferente de la autorizada; una referencia de una empresa distinta no se resuelve en el otro ámbito; `HR_REAL_DATA_ENABLED=true` devuelve 404. Ninguna respuesta negativa puede revelar ID de empresa ajena ni sede extranjera. Los fixtures usan únicamente referencias sintéticas.

## Brechas que siguen abiertas

- **UNKNOWN:** recorrido visual E2E, accesibilidad completa, operación productiva y comportamiento con usuarios reales; este archivo no acredita ninguna de esas dimensiones.
- **UNKNOWN:** persistencia contractual/laboral, documentos firmados, consentimiento, base jurídica, retención y revisión profesional.
- El permiso `users.view` prueba el control actual, no la aprobación del RBAC laboral definitivo.
- Ninguna fila de `docs/matriz-cobertura.md` se modifica ni se promueve a `CUBIERTO` por estas pruebas parciales.
- No se activan datos reales, despliegues, proveedores, secretos ni go-live. El revert del squash elimina solo este documento y el test.

La clasificación `LOCAL_PARTIAL` queda condicionada a la ejecución real de la suite y a los gates terminales del PR; antes de ello solo existe un testigo propuesto.
