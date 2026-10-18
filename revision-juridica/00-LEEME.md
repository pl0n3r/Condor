Última actualización automática: 2026-10-18 · SHA de origen: b77d8708ebe92368e66917302686f5a37347aa6d

# Documentos para revisión jurídica — Condor

Estado literal: documented_not_legally_approved. Sin aprobación jurídica.
Versión del producto: 0.1.194. Commit del repositorio de origen: b77d8708ebe92368e66917302686f5a37347aa6d.
Las copias preservan byte a byte los documentos fuente. Este paquete no autoriza go-live, publicación ni tratamiento adicional de datos.

## Documentos que debe comprobar o corregir el abogado

- [aviso-privacidad.md](aviso-privacidad.md): copia literal de docs/privacidad/aviso-privacidad.md; validar identidad, consentimiento y finalidades.
- [canal-derechos.md](canal-derechos.md): copia literal de docs/privacidad/canal-derechos.md; validar contacto, plazos y ejercicio de derechos.
- [politica-tratamiento.md](politica-tratamiento.md): copia literal de docs/privacidad/politica-tratamiento.md; validar bases, finalidades, destinatarios y garantías.
- [registro-tratamientos.md](registro-tratamientos.md): copia literal de docs/privacidad/registro-tratamientos.md; validar categorías, datos, usos y proveedores.
- [retencion.md](retencion.md): copia literal de docs/privacidad/retencion.md; validar períodos de conservación y eliminación.
- [terminos-condiciones.md](terminos-condiciones.md): copia literal de docs/privacidad/terminos-condiciones.md; validar obligaciones, niveles de servicio y reclamaciones.
- [arquitectura-entidades-legales.md](arquitectura-entidades-legales.md): copia literal de docs/arquitectura-entidades-legales.md; validar titularidad y relaciones interempresa.
- [paquete-completo.md](paquete-completo.md): los siete documentos íntegros, concatenados bajo encabezados para entregar un único archivo.

## Hechos del responsable pendientes de confirmar (sin datos reales)

- [ ] Razón social o nombre jurídico del responsable.
- [ ] Identificación, NIT e inscripción, si corresponden.
- [ ] Dirección de notificaciones y domicilio jurídico.
- [ ] Canal de derechos (correo o medio autorizado y procedimiento).
- [ ] Identidad y autoridad del representante para suscribir documentos.
- [ ] Plazos de retención, bases legales, consentimiento y revocación.
- [ ] Transferencias internacionales, contratos y roles de terceros.

## Proveedores declarados e infraestructura por confirmar

- sentry: declarado en datos.yml; validar rol jurídico, contrato, subencargo y posibles transferencias.
- Hostinger: hosting compartido declarado en AGENTES.md; confirmar contratante, rol y ubicación de datos. No se infiere su calidad de subencargado.
- GitHub Actions: automatización del repositorio; verificar si accede a datos personales reales antes de considerarlo subprocesador.

Revisión por abogado obligatoria antes de live. Generar con python scripts/generar_revision_juridica.py; verificar sin modificaciones con python scripts/generar_revision_juridica.py --check.
