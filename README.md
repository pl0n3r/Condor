# Condor App — Snapshot operativo · Password Recovery G V 0.1.72

> **Candidato objetivo:** V0.1.72 · Issue #302 · E2E final y cierre privacy-as-code de recuperación.
>
> **Producción validada:** V0.1.71 · `main@581c9ac1731d3669c077f934c1cd19e846aa0c3f` · `/health` exact-main y esquema al día.

Condor continúa en construcción. V0.1.72 cierra la evidencia funcional del parent #191 sin seleccionar ni simular un proveedor SMTP de producción.

## Alcance
- gateway de captura disponible únicamente en `APP_ENV=test` y únicamente cuando existe un mailbox efímero bajo `/tmp`;
- usuario tenant dedicado para recovery E2E, aislado de los owners usados por otras suites;
- navegador real: solicitud → captura del mensaje de prueba → reset → login con la nueva contraseña;
- navegador real: cambio autenticado → la contraseña anterior deja de autenticar → la nueva sí autentica;
- aceptación final confirma `condor_password_reset`, minimización, lifecycle y `providers: []`;
- runbook documenta la frontera entre captura E2E y gateway SMTP real fail-closed.

## Privacidad y seguridad
El mailbox E2E contiene secretos sintéticos solo durante el job descartable, vive fuera del document root, no se sube como artefacto y se elimina antes de iniciar cada ejecución. Producción no carga `services_test.yaml`, por lo que el gateway real continúa sujeto a proveedor documentado y configuración SMTP completa.

## Evidencia base
- #296–#301 completados;
- V0.1.71 / `main@581c9ac1731d3669c077f934c1cd19e846aa0c3f` exact-main antes de iniciar #302;
- `datos.yml` ya declara `condor_password_reset` con token hash/lifecycle y `providers: []`;
- #302 no añade proveedor externo, DNS, secreto real ni ruta HTTP de testing.
