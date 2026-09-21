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
  return (
    <div
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
    </div>
  );
}
