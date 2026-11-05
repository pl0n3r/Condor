# D-041-S04 y D-041-S06 — primitives accesibles y móvil

**Issue:** Condor #1605 · Padre #1546 · Corte: fase construcción, solo fixtures sintéticos.

## Implementación verificable

- `frontend/admin/DesignPrimitives.tsx` añade `Button`, `Field` y `Dialog` sin modificar `AdminShell` ni CSS compartido.
- Button conserva semántica HTML: Enter/Espacio, disabled, tipo predeterminado button y superficie mínima de 44 × 44 px.
- Field enlaza label mediante `useId`, ayuda/error por `aria-describedby`, y estado inválido mediante `aria-invalid` y `role="alert"`.
- Dialog usa `showModal` nativo para contención de foco y Escape, y retorna el foco al disparador tras cerrar. El ancho se limita al viewport; los contenidos grandes pueden desplazarse verticalmente.

## Pruebas, estado y límites

`python3 -m unittest -v tests.test_design_primitives_d041` verifica la API, testigos de accesibilidad y casos negativos que rompen al eliminar cada control. Si está disponible Chromium Playwright, la suite monta los componentes React en un fixture sintético: Espacio, Tab, Escape, restauración de foco, error accesible y ausencia de scroll horizontal a **360 px**.

Los testigos son reproducibles en estos tres primitives, pero D-041-S04/D-041-S06 globales conservan **NO_VERIFICABLE** hasta integración en flujos administrativos y PR fusionado. No acreditar WCAG integral, go-live, despliegue ni operación productiva por un fixture. No hay red, datos reales, secretos ni gasto.

**Reversión:** revertir el squash del PR de #1605; sin migraciones ni cambios de producción.
