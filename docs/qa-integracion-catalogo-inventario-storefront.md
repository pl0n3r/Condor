# QA integrado — catálogo → inventario → storefront

## Identidad y alcance

- Issue canónico: [Condor #1918](https://github.com/pl0n3r/Condor/issues/1918); épica: [Factory #2156](https://github.com/pl0n3r/Factory/issues/2156).
- Base auditada al reservar: `main@44a8ff1d382ae253d50b32c1e51f03aa313e0ce0`. Rama coordinada: `trabajo/issue-1918`; reserva V3 BOT `b4fae832-9047-4699-a04c-2c02f658537e`.
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

## Puertas de evidencia (a verificar sobre el HEAD definitivo, no sobre esta base)

- [CI Condor / Playwright Chromium](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml): ejecución E2E en navegador real, PHP 8.5, MariaDB y frontend construido. **Pendiente de resultado terminal de PR/HEAD exacto**.
- [CI Factory v1 / Validar](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml): agregado obligatorio; **pendiente del HEAD de PR**.
- [SonarCloud](https://sonarcloud.io/project/overview?id=pl0n3r_Condor), CodeQL y una única CodeRabbit FINAL: inspección del HEAD final tras CI verde, sin fingir evidencia anticipada.
- La rama y el PR permitirán comprobar el SHA final y enlaces exactos en GitHub. Un `skip`, fallo de credenciales, falta de DB descartable o `UNKNOWN` no satisface AC-01/02/03.
- D-063: `datos.yml.phase=construccion`, `nothing_live=true`, `no_real_customer_data=true`; no se confunde `VALIDATED IN CODE` con despliegue, D-043 o `VALIDATED IN PRODUCTION`.

## Reversión

Revertir el squash de este PR retira solo el archivo E2E y el reporte. No hay migración, datos persistentes externos, secretos ni cambios de producto.
