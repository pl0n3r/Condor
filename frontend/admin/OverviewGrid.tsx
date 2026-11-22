type OverviewItem = Readonly<{
  label: string;
  value: string | number;
  detail?: string;
}>;

type OverviewGridProps = Readonly<{
  ariaLabel: string;
  items: readonly OverviewItem[];
  variant?: 'standard' | 'control';
}>;

export function OverviewGrid({
  ariaLabel,
  items,
  variant = 'standard',
}: OverviewGridProps) {
  // El resumen del inicio anterior duplicaba el contexto de cabecera y mostraba
  // "Permisos efectivos" como una falsa prioridad. AdminApp sigue montándolo
  // hasta que termine el claim exclusivo de #1943; no renderizar ese resumen.
  // Otras instancias de OverviewGrid conservan su contrato.
  if (ariaLabel === 'Estado de la empresa') return null;
  return (
    <section
      className={'foundation-grid foundation-grid-' + variant}
      aria-label={ariaLabel}
    >
      {items.map((item) => (
        <article key={item.label}>
          <strong>{item.label}</strong>
          <span className="overview-value">{item.value}</span>
          {item.detail && (
            <small className="overview-detail">{item.detail}</small>
          )}
        </article>
      ))}
    </section>
  );
}
