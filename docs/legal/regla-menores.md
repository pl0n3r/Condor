# Regla de protección de datos de menores — Condor

> **BORRADOR NO APROBADO JURÍDICAMENTE.** Propuesta de política interna en
> fase de construcción/pre-live. No es un aviso de privacidad publicado ni
> acredita controles ya desplegados. Su adopción exige revisión jurídica y
> aprobación del responsable antes de presentarla a usuarios o clientes.

## Destinatarios y propósito

**Condor es una herramienta para negocios y no está dirigida a menores de
18 años.** El registro, la administración de empresas y la contratación
están previstos para personas adultas facultadas para actuar por el
negocio. Esta declaración no pretende comprobar la edad de visitantes
mediante la recopilación de una fecha de nacimiento: los formularios
públicos no deberán pedir edad ni fecha de nacimiento.

## Regla de minimización

**No se recolectarán intencionalmente datos personales de menores de edad.**
No se habilitarán formularios, campañas ni funcionalidades orientadas a
obtener datos de niños, niñas o adolescentes para fines comerciales.
Evitar pedir la edad o la fecha de nacimiento por defecto es una medida
de minimización, no una garantía de que una persona nunca aporte un dato
de un menor en un campo libre.

## Detección y eliminación de datos incidentales

**Si se detectan datos personales de menores, se eliminarán** mediante
el procedimiento seguro que corresponda, después de restringir su
uso/acceso y determinar si existe una obligación legal concreta de
conservación. No se utilizarán para publicidad, segmentación ni perfilado.
La supresión deberá extenderse a copias y procesadores dentro del
control legal y técnico del responsable, respetando plazos legítimos de
conservación; no se borrarán evidencias cuya conservación imponga la ley.
Cuando Condor actúe como **encargado** de un cliente responsable, deberá
escalar el hallazgo de manera segura y ejecutar las instrucciones
documentadas de ese responsable, sin consultar ni exponer datos reales
desde procesos de pruebas.

Este es un procedimiento **propuesto y no certificado como implementado**:
no se declara que exista ya una rutina productiva automática de detección
o borrado, ni se modifica información real mediante este documento.

## Normas de referencia y excepciones legales

El **artículo 7 de la Ley 1581 de 2012** protege los derechos prevalentes
de los niños, niñas y adolescentes en el tratamiento de datos personales.
El **Decreto 1377 de 2013**, recogido en el **Decreto 1074 de 2015,
artículo 2.2.2.25.2.9**, desarrolla garantías reforzadas: interés superior,
respeto a los derechos fundamentales y, en supuestos legalmente
procedentes, autorización del representante legal previa escucha
pertinente del menor.

La legislación contempla situaciones excepcionales y obligaciones
específicas, que requieren evaluación jurídica caso por caso. La
existencia de excepciones legales **no habilita** a Condor para recolectar
intencionalmente datos de menores mediante el producto o sus formularios
públicos, ni sustituye la revisión por el responsable.

Fuentes normativas para revisión jurídica:
- https://www1.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=49981
- https://sedeelectronica.sic.gov.co/publicaciones/boletin-juridico/concepto/tratamiento-excepcional-y-autorizacion-del-representante-legal-con-interes-superior

## Criterios de control pre-live

La prueba contractual \`tests/test_legal_minors_rule.py\` verifica este
borrador y recorre los campos y etiquetas de las plantillas públicas
Twig para detectar solicitudes de edad/fecha de nacimiento. Su cobertura
estática **no** sustituye revisión de formularios generados por
JavaScript, textos CMS creados dinámicamente, integraciones de terceros
ni comprobaciones de producción. No se proclama cumplimiento legal
integral, ni cambio de fase, ni aprobación jurídica por pasar CI.
