# Condor #1552 — ocho módulos operativos y recorridos

**Alcance:** 16 filas marcadas NO_VERIFICABLE en docs/matriz-cobertura.md. La fuente vigente es docs/modulos-operativos.md, actualizada después del snapshot de la matriz. Esta hoja ejecuta subcontratos PHP reales **con datos sintéticos**, sin modificar docs/matriz-cobertura.md, sin despliegue, sin cobros reales y sin autorizar operación comercial.

Las pruebas usan tests/test_cobertura_condor_26.py, y sus fixtures reales de tests/test_cobertura_condor_25.py y tests/test_cobertura_condor_21.py. Comprueban salidas exactas y rechazos negativos, pero nunca certifican E2E de navegador, contabilidad, pagos, proveedores ni producción.

| Fila | Testigo real exacto | Alcance y brecha |
| --- | --- | --- |
| OPS-M01-PENDIENTE | test_m01_precios_no_cobran_y_rechazan_moneda: QuotationPreview COP, USD rechazado, no cobra | NO_VERIFICABLE: política comercial/mayorista y criterios finales requieren decisión de negocio y aceptación integral |
| OPS-M01-PRUEBA | test_m01_precios_no_cobran_y_rechazan_moneda: 2461 COP, SYNTHETIC_PREVIEW, no persiste | NO_VERIFICABLE: catálogo real transaccional con variantes persistidas y precios aprobados |
| OPS-M02-PENDIENTE | test_m02_m03_rec_a_checkout_server_authority: controlador checkout 201/422/404 | NO_VERIFICABLE: integración comercial, pagos, pasarela, envíos y dominio desplegados; sin cobros reales |
| OPS-M03-PENDIENTE | test_m02_m03_rec_a_checkout_server_authority: servidor recalcula monto y rechaza variante ajena | NO_VERIFICABLE: recepción, facturación y conciliación completa con gates propios |
| OPS-M04-PENDIENTE | test_m04_rec_c_csv_branch_tenant_isolation: StockCsvAdjuster valida scope y CSV | NO_VERIFICABLE: transferencias automáticas intersede y notificaciones reales; alertas/escaneo/manuales ya constan en el código |
| OPS-M05-PENDIENTE | test_m05_customer_own_tenant_only: Customer + CommercialCategory, 5 negativas | NO_VERIFICABLE: CRM avanzado, automatización multicanal e integraciones externas |
| OPS-M06-PENDIENTE | test_m06_rec_b_receipt_synthetic_only: PurchaseOrder draft→received y 8 rechazos | NO_VERIFICABLE: conciliación, facturación y valoración contable; pantalla/costo promedio histórico ya figuran en la guía actual |
| OPS-M07-PENDIENTE | test_m07_rec_b_p4_production_estimated: orden cerrada, 7 rechazos y UI presente | NO_VERIFICABLE: valor contable definitivo; el costo visible es ESTIMATED_COST, no nómina ni contabilidad certificada |
| OPS-M08-PENDIENTE | test_m08_rec_c_rec_d_report_filters: CSV filtra tenant y fecha; UI ReportsDownloads presente | NO_VERIFICABLE: validación de descargas en producción; pantalla y filtros ya figuran en la guía actual |
| OPS-REC-A | test_m02_m03_rec_a_checkout_server_authority: monto servidor y tenant | NO_VERIFICABLE: Tienda integral, pagos, transportadora, operación real |
| OPS-REC-B | test_m06_rec_b_receipt_synthetic_only y test_m07_rec_b_p4_production_estimated: compra y BOM | NO_VERIFICABLE: Taller E2E con compra, costo y conciliación acreditados |
| OPS-REC-B-P4 | test_m07_rec_b_p4_production_estimated: ProductionOrder y ESTIMATED_COST | NO_VERIFICABLE: ERP contable o valoración legal; solo costo estimado |
| OPS-REC-C | test_m04_rec_c_csv_branch_tenant_isolation y test_m08_rec_c_rec_d_report_filters: sedes y CSV | NO_VERIFICABLE: Distribuidora integral y transferencias automáticas reales |
| OPS-REC-C-P4 | test_rec_c_p4_rec_d_p4_manual_no_automatic_cross_company: paneles manuales + negativa CSV | NO_VERIFICABLE: transferencia automática y notificación real; UI manual no equivale a automatización |
| OPS-REC-D | test_m08_rec_c_rec_d_report_filters: aislamiento por tenant, fecha | NO_VERIFICABLE: Grupo multiempresa integral y consolidación interempresas |
| OPS-REC-D-P4 | test_rec_c_p4_rec_d_p4_manual_no_automatic_cross_company: rechazo INVALID_REPORT_COLUMN + scope | NO_VERIFICABLE: consolidación/facturación conjunta, acceso cross-tenant y operación real |

## Verificación y responsabilidades pendientes

- **AC-01**: 16/16 filas documentadas y contrastadas con subcontratos ejecutables (no cobertura integral inventada). M04, M06, M07 y M08 tenían descripciones de pendiente históricas en la matriz; la guía ya fue corregida y las nuevas pruebas reflejan el código presente, no la fotografía antigua.
- **AC-02**: se ejecutan clases PHP y controlador reales con aserciones de precio, estado, tenant, CSV, moneda, variantes y negativas; si esos invariantes retroceden, las pruebas fallan. No se afirma que las mismas pruebas acrediten operación externa.
- **AC-03**: CI Factory v1 / Validar debe reportar success sobre el HEAD exacto del PR; tampoco se convierte un check skipped en success.
- **Pendientes**: aprobación de política mayorista, pagos, proveedores, logística, facturación, valoración contable, flujos UI/E2E, automatizaciones y observación de producción con autoridad humana. Mantener NO_VERIFICABLE integral y descomponer en futuras hojas V3 independientes solo con puertas y dependencias propias.
- Reversión: squash revert de los dos archivos; CI exact-HEAD y CodeRabbit FINAL una única vez tras CI verde, sin automatizaciones.
