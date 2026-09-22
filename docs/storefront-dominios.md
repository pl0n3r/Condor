# Storefront y dominios personalizados — validación operativa

Este documento describe la transición segura para conectar un dominio real a un storefront de Condor.

## Qué garantiza el código

Condor resuelve un storefront por:

- slug bajo `condorapp.com.co`; o
- hostname exacto registrado como dominio del tenant y marcado internamente como verificado.

El estado `verified` de `TenantDomain` es **registro interno de Condor**. Por sí solo no demuestra propiedad del dominio, propagación DNS, certificado TLS válido ni despliegue correcto en Hostinger.

Un host desconocido o un dominio no verificado debe fallar cerrado con HTTP 404.

## Antes de activar un dominio real

1. Confirmar que el tenant correcto existe y que el dominio fue solicitado por una persona autorizada.
2. Verificar propiedad/control del dominio fuera de Condor.
3. Configurar DNS en el proveedor correspondiente hacia el hosting productivo.
4. Esperar propagación y comprobar que el hostname resuelve al destino esperado.
5. Confirmar que Hostinger sirve el hostname y que HTTPS presenta un certificado válido para ese dominio.
6. Confirmar la identidad de la release productiva mediante versión + SHA; merge o CI verde no equivalen a deploy.
7. Solo después de esas comprobaciones, registrar el dominio como verificado/primario mediante el flujo operativo autorizado.

No ejecutar migraciones productivas, cambios DNS irreversibles ni mutaciones de infraestructura desde una validación automática.

## Smoke posterior a la activación

Validar como mínimo:

- `https://<dominio>/` responde correctamente;
- el contenido corresponde al tenant esperado;
- el `<link rel="canonical">` usa el dominio primario efectivo;
- no aparece contenido de otro tenant;
- un hostname aleatorio/desconocido responde 404;
- el slug de Condor sigue resolviendo al mismo tenant mientras esa ruta permanezca habilitada;
- no hay redirecciones inesperadas, errores 5xx ni problemas de certificado.

Registrar evidencia temporal de estas comprobaciones antes de declarar **VALIDADO EN PRODUCCIÓN**.

## Estado visible en el administrador

La interfaz puede mostrar:

- **Pendiente de verificación:** dominio registrado pero no habilitado para resolución pública.
- **Verificado en Condor:** dominio habilitado internamente; DNS/TLS todavía requieren evidencia operativa independiente.

No presentar como “activo en producción” un dominio únicamente porque exista en base de datos.

## Recuperación

Ante una activación incorrecta:

1. retirar el dominio de la resolución pública o marcarlo no verificado mediante una operación autorizada;
2. conservar el perfil público del tenant y sus datos;
3. comprobar que el host deja de resolver contenido del tenant;
4. mantener disponible el slug de Condor si el incidente no afecta esa ruta;
5. documentar causa y evidencia antes de reintentar.

La conexión del dominio y la migración de datos/esquema son transiciones separadas del despliegue de código.


## Autorización e invariante del dominio primario

El perfil público es un recurso global del tenant. Los permisos `site.view` y
`site.update` actuales están acotados por sede, por lo que un usuario delegado
puede consultar el estado del storefront pero **no** modificar la identidad
global. Mientras no exista un permiso explícitamente tenant-wide, la edición se
reserva al propietario del tenant.

La base de datos mantiene como máximo un dominio que sea simultáneamente
`primary` y `verified` por tenant. La columna indexada
`primary_verified_tenant_id` es **generada por MariaDB**, no calculada por PHP:
solo contiene el ID del tenant cuando ambas banderas son verdaderas, y es
`NULL` en los demás casos. Así, una instancia vieja de Condor que inserte o
actualice dominios sin conocer esa columna sigue sometida a la misma unicidad
durante un despliegue parcial. Doctrine la trata como read-only y nunca intenta
escribirla. La migración aborta si encuentra datos históricos ambiguos antes
de instalar la restricción; no corrige datos reales ni ejecuta el cambio en
producción automáticamente.
