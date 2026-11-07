# Contratación M02 — HTTP sintético por empresa y sede (#1787)

**Corte de #1577 y #1527.** Fase de construcción, sin empleados ni firmas laborales reales. La directriz del dueño del 6-nov-2026 permite trabajo con datos inventados; no sustituye la decisión B de #742 ni autoriza producción, proveedores, datos personales o validez jurídica.

## Reproducir la prueba

Con Composer, Symfony APP_ENV=test y MariaDB descartable configurada por CI, ejecutar:

    vendor/bin/simple-phpunit tests/php/Http/ContractsSyntheticHttpAcceptanceTest.php

PHPUnit descubre automáticamente el archivo en la suite tests/php. El WebTestCase usa el kernel y router auténticos, Doctrine, auditoría, sesiones, permisos y CSRF; no usa mocks del router ni DNS y no se conecta a datos de clientes. El contrato ejecutivo de esta hoja exige Pruebas de contrato e integración, CI Factory v1 / Validar, SonarCloud, CodeQL y CodeRabbit FINAL sobre HEAD exacto antes de fusionar.

| Fila | Aserción del HTTP de Symfony | Evidencia atribuible | Brecha persistente |
| --- | --- | --- | --- |
| GEST-M02-S01: plantillas | POST de plantilla sintética con CSRF; estado REQUIERE_REVISION | LOCAL_PARTIAL tras CI verde | No existe versión jurídicamente aprobada |
| GEST-M02-S02: contratos | POST/GET de borrador, PATCH EN_REVISION y rechazo de APROBADO | LOCAL_PARTIAL tras CI verde | No existe contrato firmado, expediente laboral ni liquidación real |
| GEST-M02-S03: otrosíes | POST de addendum ficticio con CSRF; mismo aislamiento de sesión y compañía | LOCAL_PARTIAL tras CI verde | No valida todavía motivos, versión original ni aprobación jurídica |
| Empresa/sede y tenancy | Dos tenants, dos razones sociales de A y dos sedes en una; listas cruzadas vacías y tenant B responde 404 | LOCAL_PARTIAL tras CI verde | No cubre todas las rutas multiempresa ni operación productiva |
| Autorización y validación | Anónimo 401, delegated viewer no puede crear (403), sin CSRF 403, campos extra y referencias no sintéticas 422 | LOCAL_PARTIAL tras CI verde | Permisos legacy inventory.*; no acredita RBAC laboral definitivo |

## Brechas y puertas que NO resuelve este PR

- La interfaz de S01–S03 sigue en memoria; #1788 cubre la conexión HTTP y los estados loading/empty/error/permission-denied.
- S04–S08 siguen como proyecciones offline; #1789 prepara vistas previas HTTP con revisión humana, sin efectos jurídicos.
- Persistencia real y pantalla definitiva: #1654 y #1655, con CONTRACTS_REAL_DATA_ENABLED apagado por defecto. Los claims de esos issues no se modifican aquí.
- Los controles laborales y de datos personales D-059/D-063, autorización jurídica del dueño, certificados de firma y go-live continúan UNKNOWN. CI verde no equivale a VALIDATED_IN_PRODUCTION.
- docs/matriz-cobertura.md y docs/verificacion/gest-m02.md no se modifican; #1793 consolida referencias cuando los slices terminen.

## Revertir

La fusión squash de #1787 incorpora exactamente este documento y tests/php/Http/ContractsSyntheticHttpAcceptanceTest.php. Un revert elimina el testigo sin alterar esquema, frontend, controladores, sesiones ni datos productivos. Este corte por sí solo **no cierra #1577**.
