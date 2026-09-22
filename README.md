# Condor App — Snapshot operativo · candidato V 0.1.16

[![CI Condor](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg)](https://github.com/pl0n3r/Condor/actions/workflows/ci.yml)
[![SonarQube Cloud](https://sonarcloud.io/api/project_badges/measure?project=pl0n3r_Condor&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=pl0n3r_Condor)

> **Objetivo actual:** cerrar la brecha entre código integrado y evidencia real de producción: identidad exacta versión+SHA, observación automática read-only y smoke público del storefront sin declarar transiciones operativas humanas por automatismo.

<p align="center">
  <strong>Producto:</strong> Condor App ·
  <strong>Runtime:</strong> PHP 8.5 ·
  <strong>Base:</strong> V 0.1.15 · main `0a60bafa` ·
  <strong>Candidato:</strong> V 0.1.16 ·
  <strong>Producción observada:</strong> V 0.1.14 · SHA todavía no verificable
</p>

## Estado de entrega

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base integrada | ✅ **V 0.1.15 / EXACT-MAIN VERDE** | SHA `0a60bafad39ef1b6bbda4bb475a7a5a005b157d4`; Catálogo + E2E real del propietario |
| Candidato actual | 🚧 **V 0.1.16 EN VALIDACIÓN** | Issues #153 + #161 / PR #154 |
| Identidad de release | ✅ **IMPLEMENTADA EN CANDIDATO** | `RELEASE_SHA` con fallback seguro a `.git/HEAD`, refs sueltas y `packed-refs` |
| Storefront en smoke | ✅ **IMPLEMENTADO EN CANDIDATO** | canonical, hero/H1 visible, identidad tenant y 404 de slug inexistente |
| Observación automática | ✅ **IMPLEMENTADA EN CANDIDATO** | push completo de `main`, modo read-only y transición operativa fail-closed |
| Producción observada | 🚧 **V 0.1.14, SHA NO VERIFICABLE AÚN** | #161 detectó `release_sha=dev` |
| Producción V 0.1.16 | ⏳ **NO VALIDADA** | merge/CI no equivalen a deploy ni a transición productiva |

## Qué incorpora V 0.1.16

- SHA de release verificable sin `shell_exec`, incluyendo referencias Git empaquetadas.
- Observación automática después de pushes a `main`, comparando todos los commits del push y no solo el último.
- Clasificación fail-closed de cambios que requieren migraciones, roles, comandos, configuración o caché.
- Storefront autorizado configurable mediante variables de repositorio para el smoke automático.
- Validación pública del storefront: canonical exacto, hero real, H1 visible, identidad tenant y 404 sin redirect para slugs inexistentes.
- Parser HTML endurecido ante contenido inerte, secciones anidadas y cierres implícitos de `head`.
- Evidencia de release separada del manifiesto de checks y publicada en el resumen de GitHub.
- Ninguna mutación automática de producción ni confirmación automática de transiciones que requieren intervención humana.

## Qué sigue

- Cerrar CI, SonarQube y CodeRabbit sobre el SHA exacto final de V 0.1.16.
- Integrar serialmente solo si el candidato queda verde, sin findings válidos y sin colisiones.
- Observar el deploy real por separado; si la release requiere transición operativa, el máximo automático sigue siendo `DEPLOY_OBSERVED`.
- Continuar después con los frentes V 0.1.17+ ya reservados, sincronizándolos contra el `main` que resulte.
- El Roadmap canónico continúa en Issue #1.

> **Regla de estado:** CI verde o merge prueban código, no producción. Solo evidencia real de deploy y smoke permite avanzar el estado productivo, y las transiciones operativas conservan su autorización separada.
