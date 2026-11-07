# Condor · GEST-M04-S09 — preparación electrónica exclusivamente offline

**Issue:** [#1634](https://github.com/pl0n3r/Condor/issues/1634) · **Dependencia:** #1529 completada · **Fase:** construcción. La decisión B de #742 continúa vigente.

## Contrato verificado en código

- Clase pura: **ElectronicPayrollReadiness::plan**, en src/Application/Payroll/ElectronicPayrollReadiness.php, contrato versionado **electronic-payroll-readiness-v1**.
- La entrada exige referencias de un **catálogo cerrado** de fixtures ficticios: tenant = `fixture:tenant/demo` o `fixture:tenant/foreign`, empresa = `fixture:company/demo` o `fixture:company/foreign`, documento = `fixture:document/demo` o `fixture:document/example`. Los prefijos `fixture:` por sí solos no garantizan datos ficticios: `fixture:document/123456789` y otros sufijos arbitrarios se rechazan. Requiere además marca `synthetic` verdadera y modo `offline`. Los valores de tenant y empresa del actor y el permiso de preparación deben venir del contexto autenticado del servidor (no del formulario). Se exige coincidencia estricta de ambos scopes y permiso explícito; sin permiso la operación falla cerrada.
- La entrada tiene un esquema cerrado. Campos imprevistos, identificadores no ficticios, tenant o empresa ajenos, ausencia de permiso, modo live o solicitud de envío se rechazan con códigos genéricos, sin reproducir el contenido.
- En toda respuesta, válida o rechazada, el estado es **NO_HABILITADO**, la revisión es **PENDIENTE_REVISION**, y producción, transmisión, llamadas a proveedores y efecto legal son **false**. No se genera acuse de recibo.
- El método `plan($input, $actorTenantRef, $actorCompanyRef, $mayPrepare=false)` es una función pura de preparación offline. La autorización empresarial y el permiso se validan contra parámetros confiables aportados por el servidor. No hay controlador nuevo ni autorización HTTP real acreditada.
- El contrato **nunca** comunica con DIAN ni transforma un documento real. No ofrece autorización legal para nómina electrónica.

## Matriz de aceptación y pruebas

Suite: tests/php/Application/Payroll/ElectronicPayrollReadinessTest.php, mediante PHPUnit y fixtures ficticios.

| AC | Prueba automatizada | Alcance y límite |
| --- | --- | --- |
| AC-01 | Preview sintético con tenant, empresa y documento válidos, estado deshabilitado y ausencia de acuse | No valida XML, transmisión ni acuses DIAN |
| AC-02 | Negativos de tenant ajeno, empresa ajena, permiso denegado, empresa ausente o inválida, identificadores no ficticios incluso con prefijo `fixture:`, campos personales, tipos incorrectos, live y envío | No utiliza datos reales ni redes |
| AC-03 | Producción apagada incluso para datos inválidos, pruebas automáticas y CI Factory v1 / Validar exacto al HEAD final | CI verde no equivale a salida electrónica habilitada |
| AC-04 | Ningún envío, efecto legal, acuse o acreditación de cumplimiento | Puerta jurídica y de proveedor independientes y aún pendientes |

Comandos reproducibles de validación local:

    php -l src/Application/Payroll/ElectronicPayrollReadiness.php
    php -l tests/php/Application/Payroll/ElectronicPayrollReadinessTest.php
    vendor/bin/simple-phpunit --filter ElectronicPayrollReadinessTest
    vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G

No se declara PASS de estos comandos hasta obtener ejecución auténtica. La suite se ejecuta sobre código sintético, no sobre DIAN ni producción.

## Evidencia necesaria antes de cualquier fase real

Se necesitarían **separadamente**: decisión sucesora del dueño que cambie la restricción B de #742 para un alcance específico; revisión/aprobación jurídica; identidad y habilitación auténticas del proveedor o canal DIAN; tratamiento de datos autorizado; pruebas de seguridad, retención y auditoría; evidencia verificable de envío y acuse; y autorización humana del despliegue y go-live. Ninguno de estos requisitos se supone cumplido aquí.

**Reversión:** revert del squash de #1634. Esta hoja no crea migraciones, endpoints de publicación, credenciales, flags live ni tareas automáticas y no toca datos.yml o docs/matriz-cobertura.md.
