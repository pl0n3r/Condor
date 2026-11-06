import { useEffect, useId, useRef, type ReactNode } from 'react';

/** Kit de componentes del administrador: las mismas piezas en todos los módulos (cabecera, métricas, barra de filtros,
 *  tabla, estados, panel lateral). Sin recursos externos; accesible por teclado. */

export type Tone = 'neutral' | 'ok' | 'warn' | 'danger' | 'info';

export function StatusPill({ tone = 'neutral', children }: Readonly<{ tone?: Tone; children: ReactNode }>) {
  return <span className={'ui-pill ui-pill-' + tone}>{children}</span>;
}

export function PageHeader({ title, description, actions }: Readonly<{ title: string; description?: string; actions?: ReactNode }>) {
  return (
    <header className="ui-page-header">
      <div>
        <h2>{title}</h2>
        {description && <p>{description}</p>}
      </div>
      {actions && <div className="ui-page-actions">{actions}</div>}
    </header>
  );
}

export type Metric = Readonly<{ label: string; value: string | number; hint?: string; tone?: Tone }>;

export function MetricStrip({ items, label }: Readonly<{ items: readonly Metric[]; label: string }>) {
  return (
    <dl className="ui-metrics" aria-label={label}>
      {items.map((item) => (
        <div className="ui-metric" key={item.label}>
          <dt>{item.label}</dt>
          <dd>{item.value}</dd>
          {item.hint && <span className={'ui-metric-hint ui-tone-' + (item.tone ?? 'neutral')}>{item.hint}</span>}
        </div>
      ))}
    </dl>
  );
}

export function Toolbar({ children, trailing }: Readonly<{ children: ReactNode; trailing?: ReactNode }>) {
  return (
    <fieldset className="ui-toolbar">
      <legend className="ui-sr-only">Filtros</legend>
      <div className="ui-toolbar-filters">{children}</div>
      {trailing && <div className="ui-toolbar-trailing">{trailing}</div>}
    </fieldset>
  );
}

export type Column<T> = Readonly<{
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  align?: 'right';
}>;

export function DataTable<T>({
  caption, columns, rows, rowKey, onOpen, empty,
}: Readonly<{
  caption: string;
  columns: readonly Column<T>[];
  rows: readonly T[];
  rowKey: (row: T) => string;
  onOpen?: (row: T) => void;
  empty: ReactNode;
}>) {
  if (rows.length === 0) return <div className="ui-empty">{empty}</div>;
  return (
    <div className="ui-table-wrap">
      <table className="ui-table">
        <caption className="ui-sr-only">{caption}</caption>
        <thead>
          <tr>
            {columns.map((column) => (
              <th scope="col" key={column.key} className={column.align === 'right' ? 'ui-right' : undefined}>{column.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)} className={onOpen ? 'ui-row-action' : undefined}>
              {columns.map((column, index) => (
                <td key={column.key} className={column.align === 'right' ? 'ui-right' : undefined}>
                  {index === 0 && onOpen ? (
                    <button type="button" className="ui-link" onClick={() => onOpen(row)}>{column.render(row)}</button>
                  ) : column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Panel lateral (detalle o formulario): usa <dialog> nativo para foco, Escape y lectores de pantalla. */
export function SidePanel({ open, title, onClose, children, footer }: Readonly<{
  open: boolean; title: string; onClose: () => void; children: ReactNode; footer?: ReactNode;
}>) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  useEffect(() => {
    const dialog = ref.current;
    if (!dialog || !open) return;
    const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (!dialog.open) dialog.showModal();
    const closeButton = dialog.querySelector<HTMLButtonElement>('.ui-icon-button');
    closeButton?.focus();
    return () => {
      if (dialog.open) dialog.close();
      if (opener?.isConnected) opener.focus();
    };
  }, [open]);
  return (
    <dialog
      ref={ref}
      className="ui-sidepanel"
      aria-labelledby={titleId}
      aria-modal="true"
      onCancel={(event) => { event.preventDefault(); onClose(); }}
    >
      <div className="ui-sidepanel-card">
        <header>
          <h3 id={titleId}>{title}</h3>
          <button type="button" className="ui-icon-button" aria-label="Cerrar" onClick={onClose}>
            <svg aria-hidden="true" focusable="false" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M5 5l10 10M15 5L5 15" /></svg>
          </button>
        </header>
        <div className="ui-sidepanel-body">{children}</div>
        {footer && <footer>{footer}</footer>}
      </div>
    </dialog>
  );
}

export function Card({ title, action, children }: Readonly<{ title?: string; action?: ReactNode; children: ReactNode }>) {
  return (
    <section className="ui-card">
      {(title || action) && (
        <header><h3>{title}</h3>{action}</header>
      )}
      {children}
    </section>
  );
}
