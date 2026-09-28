# Recuperación de contraseña: operación y evidencia

Estado: contrato técnico de construcción. No constituye selección de proveedor ni aprobación jurídica.

## Producción

La recuperación usa `condor_password_reset` y persiste únicamente `token_hash` y datos de lifecycle. `datos.yml` mantiene `providers: []`: mientras no exista proveedor de correo documentado y configuración SMTP completa, `SymfonyMailerTransactionalEmailGateway` permanece fail-closed.

El token crudo no se persiste, no entra en logs/analytics y viaja al navegador únicamente en el fragmento de la URL canónica HTTPS.

## E2E

`config/services_test.yaml` sustituye el gateway únicamente cuando Symfony ejecuta `APP_ENV=test`. `E2eFileTransactionalEmailGateway` además exige `CONDOR_E2E_MAILBOX_PATH` bajo el directorio temporal del sistema. Sin ambas condiciones reporta `isAvailable() === false`.

En CI el mailbox es `/tmp/condor-e2e-mailbox.jsonl`. Contiene exclusivamente datos sintéticos durante el job descartable, se elimina antes de arrancar la aplicación y no forma parte de los artefactos de fallo. Esta captura no representa ni declara un proveedor externo.

El navegador solicita recuperación para `recovery-e2e@example.test`, el runner obtiene la URL del mailbox local, ejecuta reset, demuestra login con la contraseña nueva, realiza cambio autenticado y comprueba que la contraseña anterior ya no autentica.

## Go-live futuro

Antes de correo real deben documentarse proveedor, tratamiento aplicable, credenciales fuera del repositorio, DNS de entrega cuando corresponda y evidencia operativa. Ninguno de esos hechos se presume en V0.1.72.
