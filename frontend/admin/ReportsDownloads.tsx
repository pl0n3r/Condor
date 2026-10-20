import { useEffect, useRef, useState } from 'react';

type Kind = 'inventory' | 'sales' | 'purchases';
type Status = 'idle' | 'loading' | 'success' | 'empty'
  | 'denied' | 'not-found' | 'invalid' | 'unavailable' | 'error';
type Props = Readonly<{ branchId: string; permissions: readonly string[] }>;

const types: ReadonlyArray<{ kind: Kind; label: string; permission: string }> = [
  { kind: 'inventory', label: 'Inventario', permission: 'inventory.view' },
  { kind: 'sales', label: 'Ventas', permission: 'orders.view' },
  { kind: 'purchases', label: 'Compras', permission: 'inventory.view' },
];

function validDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number(value.slice(0, 4)) < 1) return false;
  const parsed = new Date(value + 'T00:00:00Z');
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

function downloadPath(branchId: string, kind: Kind, from: string, through: string): string {
  const query = new URLSearchParams({ from, through });
  return '/api/v1/branches/' + encodeURIComponent(branchId)
    + '/reports/' + kind + '.csv?' + query.toString();
}

export function ReportsDownloads({ branchId, permissions }: Props) {
  const [kind, setKind] = useState<Kind>('inventory');
  const [from, setFrom] = useState('');
  const [through, setThrough] = useState('');
  const [status, setStatus] = useState<Status>('idle');
  const sequence = useRef(0);
  const active = useRef<AbortController | null>(null);
  const urls = useRef(new Set<string>());
  const branchRef = useRef(branchId);
  branchRef.current = branchId;
  const allowed = types.filter((item) => permissions.includes(item.permission));
  const selected = allowed.some((item) => item.kind === kind) ? kind : allowed[0]?.kind;
  const canDownload = Boolean(selected && branchId && validDate(from)
    && validDate(through) && from <= through && status !== 'loading');

  function cancel() {
    sequence.current += 1;
    active.current?.abort();
    active.current = null;
    setStatus('idle');
  }

  useEffect(() => {
    cancel();
    return () => {
      sequence.current += 1;
      active.current?.abort();
      for (const url of urls.current) URL.revokeObjectURL(url);
      urls.current.clear();
    };
  }, [branchId]);

  async function download() {
    if (!selected || !canDownload) { setStatus('invalid'); return; }
    const originalBranch = branchId;
    const request = ++sequence.current;
    active.current?.abort();
    const controller = new AbortController();
    active.current = controller;
    setStatus('loading');
    const current = () => request === sequence.current
      && branchRef.current === originalBranch && !controller.signal.aborted;
    try {
      const response = await fetch(downloadPath(originalBranch, selected, from, through), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'text/csv' },
        signal: controller.signal,
      });
      if (!current()) return;
      if (!response.ok) {
        const failure: Status = response.status === 401 || response.status === 403
          ? 'denied' : response.status === 404 ? 'not-found'
          : response.status === 400 ? 'invalid' : response.status === 503
          ? 'unavailable' : 'error';
        setStatus(failure);
        return;
      }
      if (!(response.headers.get('Content-Type') ?? '').toLowerCase().includes('text/csv')) {
        setStatus('error');
        return;
      }
      const blob = await response.blob();
      if (!current()) return;
      // The backend always emits a header, even when no data row was found.
      const csv = await blob.text();
      if (!current()) return;
      if (csv.trimEnd().split(/\r?\n/).length <= 1) {
        setStatus('empty');
        return;
      }
      const objectUrl = URL.createObjectURL(blob);
      urls.current.add(objectUrl);
      try {
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        // Fixed allowlisted basename: do not trust Content-Disposition input.
        anchor.download = selected + '-' + from + '-' + through + '.csv';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setStatus('success');
      } finally {
        setTimeout(() => {
          URL.revokeObjectURL(objectUrl);
          urls.current.delete(objectUrl);
        }, 0);
      }
    } catch {
      if (current()) setStatus('error');
    } finally {
      if (request === sequence.current) active.current = null;
    }
  }

  if (allowed.length === 0) {
    return <section id="reports-downloads" aria-label="Descargar reportes">
      <h2>Descargar reportes</h2>
      <p role="alert">No tienes permisos para descargar reportes de esta sede.</p>
    </section>;
  }
  return (
    <section id="reports-downloads" aria-label="Descargar reportes">
      <h2>Descargar reportes</h2>
      <p className="muted">Exporta registros de la sede activa como CSV.</p>
      <form onSubmit={(event) => { event.preventDefault(); void download(); }}>
        <label>Tipo de reporte
          <select value={selected} onChange={(event) => {
            cancel(); setKind(event.target.value as Kind);
          }}>
            {allowed.map((item) =>
              <option key={item.kind} value={item.kind}>{item.label}</option>)}
          </select>
        </label>
        <label>Desde
          <input type="date" required value={from} onChange={(event) => {
            cancel(); setFrom(event.target.value);
          }} />
        </label>
        <label>Hasta
          <input type="date" required value={through} onChange={(event) => {
            cancel(); setThrough(event.target.value);
          }} />
        </label>
        <button type="submit" disabled={!canDownload}>
          {status === 'loading' ? 'Preparando CSV…' : 'Descargar CSV'}
        </button>
      </form>
      {status === 'loading' && <output aria-live="polite">Generando reporte…</output>}
      {status === 'success' && <output aria-live="polite">Descarga iniciada.</output>}
      {status === 'empty' && <output aria-live="polite">Sin registros en el rango seleccionado.</output>}
      {status === 'denied' && <p role="alert">Acceso denegado al reporte de esta sede.</p>}
      {status === 'not-found' && <p role="alert">La sede o el reporte no está disponible.</p>}
      {status === 'invalid' && <p role="alert">Selecciona un rango de fechas válido.</p>}
      {status === 'unavailable' && <p role="alert">El servidor de reportes no está disponible. Puedes reintentar.</p>}
      {status === 'error' && <p role="alert">No se pudo descargar el reporte. Puedes reintentar.</p>}
    </section>
  );
}
