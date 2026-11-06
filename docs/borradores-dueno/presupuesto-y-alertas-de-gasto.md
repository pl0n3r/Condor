# Propuesta: presupuesto y alertas de gasto para producción (Condor #1493)

Borrador para que el dueño ponga los montos. No hay ningún monto inventado: donde falta un dato aparece `[monto]`.
Regla vigente (decisión del dueño, 30-oct-2026, FSP-18): **sin gastos nuevos**. Ningún agente compra planes ni provisiona servicios; solo se usa lo que ya se paga y las capas gratuitas. Toda compra nueva exige decisión explícita y recibo.

## 1. Gastos que existen hoy

| Concepto | Qué es | Monto mensual |
|---|---|---|
| ChatGPT Plus (3 cuentas) | Los chats que trabajan los issues | `[monto]` |
| Hostinger | Hosting de los productos y de ControlBot | `[monto]` |
| Dominios | `condorapp.com.co` y los de los productos | `[monto]` |
| GitHub | Plan de la organización y minutos de Actions | `[monto]` |
| SonarCloud / CodeRabbit | Calidad y revisión automática | `[monto]` |
| Modelos locales (Qwen en el PC) | Sin costo por uso; solo electricidad | `$0` |
| **Total mensual de hoy** | | `[monto]` |

## 2. Gastos que aparecerán al activar producción (hoy sin proveedor)

| Concepto | Estado | Monto mensual estimado |
|---|---|---|
| Correo transaccional | Sin proveedor elegido (`datos.yml: providers: []`) | `[monto]` |
| Pasarela de pagos | Sin proveedor; hoy solo pagos manuales | Comisión por venta, `[porcentaje]` |
| Monitoreo de errores (Sentry) | Sin cuenta | `[monto]` |
| Almacenamiento externo (S3 compatible) | Sin proveedor | `[monto]` |
| Abogado de privacidad | Sin contratar | Pago único, `[monto]` |

## 3. Presupuesto propuesto (completar)

- **Tope mensual total:** `[monto]`
- **Límite por proveedor:** cada línea de las tablas con su propio tope `[monto]`.
- **Margen de imprevistos:** `[porcentaje]` del tope total.

## 4. Alertas

| Nivel | Qué significa | Qué pasa |
|---|---|---|
| 50 % | Mitad del tope del mes | Aviso informativo al dueño |
| 80 % | Cerca del tope | Aviso al dueño y a la persona de respaldo; se frena todo gasto opcional |
| 100 % | Tope alcanzado | Aviso urgente; se aplica el plan de excedentes |

- **Destinatarios:** el dueño y una persona de respaldo `[nombre / correo]`.
- **Dónde llegan:** el canal de avisos de ControlBot (módulo de soporte, destinatarios configurables, issue ControlBot #1428).
- **Prueba de entrega:** al configurar, se dispara una alerta de prueba y se guarda la evidencia (captura del aviso recibido).

## 5. Plan si se pasa el tope

1. Congelar todo gasto opcional (planes nuevos, almacenamiento extra).
2. Revisar qué línea se disparó y por qué.
3. Si es un proveedor, bajar de plan o pausar su servicio no esencial.
4. **Apagado controlado:** si el gasto no se contiene, se pausa en este orden: tareas de fondo, nuevos registros de clientes, y por último el sitio. Los datos y los respaldos no se tocan.
5. El dueño decide si se sube el tope. Ningún agente lo sube por su cuenta.

## 6. Qué falta de tu parte

- Poner los montos de la sección 1 y 3.
- Decidir el nombre y el correo de la persona de respaldo.
- Confirmar si quieres que la alerta del 100 % apague algo automáticamente o solo avise.
