# Manual · Servicio al cliente

## Para qué sirve
**Esta sección es un simulador de desarrollo, no un servicio de atención real.** Permite practicar con casos ficticios y ver cómo se presentarían sus estados.

## Antes de empezar
El módulo solo se muestra en el entorno de desarrollo. Usa exclusivamente ejemplos inventados; no ingreses información de clientes reales. Para crear o cambiar casos se necesitan los permisos correspondientes.

## Cómo se usa (paso a paso)
1. En «Servicio al cliente», pulsa «Nuevo caso de prueba».
2. En «Referencia sintética», escribe una referencia de ejemplo como «fixture:case/demo».
3. En «Tipo», elige entre «PQRS», «Garantía» o «Solicitud» y pulsa «Crear caso de prueba».
4. En «Filtrar por estado», selecciona «Todos», «Requiere revisión» o «En revisión».
5. Selecciona un caso de la tabla para abrir «Detalle y estado». Si tienes permiso, pulsa «Pasar explícitamente a revisión».
6. Consulta «Bitácora de demostración (no oficial)» para ver los movimientos de ejemplo durante la sesión.

## Avisos y mensajes
«Caso ficticio creado. Pendiente de revisión.» confirma un caso de prueba. «Transición de prueba registrada para revisión humana.» aparece al cambiar el estado. «Solo se admiten referencias de prueba nuevas, como fixture:case/demo.» indica que debes corregir la referencia.

## Limitaciones
Los casos se guardan únicamente en la pantalla durante la sesión, sin guardado en servidor ni registro oficial. No se atienden solicitudes reales, no hay aprobaciones ni cierres automáticos y el simulador admite como máximo 100 casos de prueba. No está habilitado en producción.