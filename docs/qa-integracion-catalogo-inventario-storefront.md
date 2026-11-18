# QA integrado — catálogo → inventario → storefront

## Identidad y alcance

- Issue canónico: [Condor #1918](https://github.com/pl0n3r/Condor/issues/1918); épica: [Factory #2156](https://github.com/pl0n3r/Factory/issues/2156).
- Base auditada al reservar: `main@44a8ff1d382ae253d50b32c1e51f03aa313e0ce0`. Rama coordinada: `trabajo/issue-1918`; reserva V3 BOT recuperada `57745d3d-5916-4285-9b6b-78d712d9fb1d` (lease inicial `b4fae832-9047-4699-a04c-2c02f658537e`).
- Prueba nueva: [integrated-catalog-inventory-storefront.spec.mjs](../tests/e2e/integrated-catalog-inventory-storefront.spec.mjs).
- Solo PHP/Symfony + MariaDB descartable y navegador Chromium del workflow CI; no cambia infraestructura, controladores, fixtures compartidos, `package.json`, migraciones ni producción. Nunca utilizar usuarios/órdenes de clientes reales.
- Dos tenants sintéticos provisionados por `.github/workflows/ci.yml`: `E2E_EMAIL` y `E2E_RECOVERY_EMAIL`, con sus respectivos secretos efímeros. Si falta alguno, el test **falla**, no usa `skip` ni simula el permiso del backend con stubs.

## Matriz de trazabilidad AC-01/02/03

| Operación | API / persistencia MariaDB | UI/SSR / evidencia afirmada solo al pasar Chromium |
| --- | --- | --- |
| Alta de catálogo A | `GET /api/v1/branches/{branch}/catalog/products`; misma entidad de producto + variante por ID/SKU, sin duplicados | `/admin#catalog`, alta con fixture compartido; recarga pública muestra el mismo SKU |
| Fuente y stock A=5 | `GET /api/v1/branches/{branch}/inventory`; una fuente y un único balance `source_id+variant.id` con cantidad 5 | `/admin#inventory` y `/{tenantA}` publican `Disponible` |
| Precio/canal A | `GET /api/v1/branches/{branch}/pricing`; IDs de variante y lista persistidos | `/admin#commerce` y `/admin/storefront`; `/{tenantA}` publica `COP 125.000` para el mismo SKU |
| Agotado y actualización | Ajuste `-5` vía Admin, mismo balance queda 0; `POST /{tenantA}/checkout` sin stock es rechazado | `/{tenantA}` y recarga presentan `Agotado` sin fallback de fuente |
| Reposición | Ajuste `+5` vía Admin; el único balance vuelve a 5 | `/{tenantA}` y recarga vuelven a `Disponible`, mismo canal/lista/SKU/precio |
| Aislamiento B | Sesión distinta, `GET` a rutas de sucursal A = 404; B consulta sus propios snapshots, ninguno contiene entidades de A | `/{tenantB}` no publica SKU ni producto de A |
| Mutaciones ajenas | CSRF auténtico de B; `PATCH` a fuente A desde rama A y `POST` con `source_id` A desde B = 404 | Verificación posterior del balance de A garantiza ausencia de escritura cruzada |

**Criterios negativos estrictos.** El harness nunca usa `page.route().fulfill()` para hacer pasar el aislamiento. El acceso y la denegación se verifican contra el servidor Symfony real y la misma base MariaDB efímera. Se cierra el contexto navegador de B incluso si fallan las aserciones.

## Evidencia real del recorrido integrado

- **Prueba de implementación:** `72c53656141bb103fbb6aaf9a0a1f749a51924ff` (el E2E no cambia en esta actualización documental).
- **[Condor CI #44316225065, intento 2](https://github.com/pl0n3r/Condor/actions/runs/44316225065):** `completed/success` para el mismo HEAD de implementación. `Playwright Chromium`, `Pruebas de contrato e integración`, `Frontend TypeScript / build`, `Auditoría de dependencias npm`, `CI Factory v1 / Validar` y agregado Condor `Validar` terminaron `success`; SonarCloud y CodeQL, `success`. No se han omitido los negativos de tenant B.
- **Bloqueo resuelto con fuente externa, sin suprimir auditoría:** el [advisory GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf) fue **retirado el 18-nov-2026** por GitHub; el npm audit del intento 1 falló por ese aviso, y el intento 2 **pasó con los mismos `package.json` y `package-lock.json`**, sin downgrade, overrides o excepciones. No afirmar que se publicó un parche upstream ni que la eliminación del aviso certifique toda seguridad futura.
- **Identidad de PR y límite:** [PR #1919](https://github.com/pl0n3r/Condor/pull/1919) conserva claim documental y de prueba, sin operaciones de producción. Esta revisión del reporte crea un HEAD documental posterior, que debe pasar de nuevo `Validar` y los controles de seguridad sobre ese SHA antes de CodeRabbit FINAL y squash.
- **AC-01/02/03:** evidencia de prueba E2E real, backend Symfony/MariaDB descartable y Chromium; **AC-04:** éxito sobre HEAD de implementación, pendiente revalidación del HEAD documental definitivo. Evidencia `VALIDATED IN CODE` solamente; no implica `DEPLOY_OBSERVED`, `VALIDATED_IN_PRODUCTION` ni D-043.

## Puertas finales (revalidar HEAD documental antes de merge)

- [CI Condor / Playwright Chromium](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml): ejecución E2E en navegador real, PHP 8.5, MariaDB y frontend construido. **Success en HEAD de implementación (run #44316225065); revalidación final documental pendiente**.
- [CI Factory v1 / Validar](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml): agregado obligatorio; **Success en HEAD de implementación (run #44316225065); revalidación final documental pendiente**.
- [SonarCloud](https://sonarcloud.io/project/overview?id=pl0n3r_Condor), CodeQL y una única CodeRabbit FINAL: inspección del HEAD final tras CI verde, sin fingir evidencia anticipada.
- La rama y el PR permitirán comprobar el SHA final y enlaces exactos en GitHub. Un `skip`, fallo de credenciales, falta de DB descartable o `UNKNOWN` no satisface AC-01/02/03.
- D-063: `datos.yml.phase=construccion`, `nothing_live=true`, `no_real_customer_data=true`; no se confunde `VALIDATED IN CODE` con despliegue, D-043 o `VALIDATED IN PRODUCTION`.

## Reversión

Revertir el squash de este PR retira solo el archivo E2E y el reporte. No hay migración, datos persistentes externos, secretos ni cambios de producto.
