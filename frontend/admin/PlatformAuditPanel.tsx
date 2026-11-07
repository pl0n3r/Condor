import { useEffect, useState, type FormEvent } from 'react';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

type Snapshot = Record<string, string | number | boolean | null>;
type AuditRow = {
  id: string;
  actor: string;
  created_at: string;
  action: string;
  object: { type: string; id: string };
  before: Snapshot;
  after: Snapshot;
};
type AuditPage = {
  items: AuditRow[];
  page: number;
  per_page: number;
  total: number;
  has_next: boolean;
};
type Filters = { actor: string; action: string; type: string; from: string; through: string };
type LoadState =
  | { status: 'loading' }
  | { status: 'denied' }
  | { status: 'error' }
  | { status: 'ready'; data: AuditPage };

const emptyFilters: Filters = { actor: '', action: '', type: '', from: '', through: '' };

function readableDate(utc: string): string {
  const parsed = new Date(utc);
  return Number.isNaN(parsed.getTime()) ? 'Fecha desconocida' : parsed.toLocaleString('es-CO', { timeZone: 'UTC' }) + ' UTC';
}

function safeSnapshot(snapshot: Snapshot): string {
  const entries = Object.entries(snapshot);
  return entries.length ? entries.map(([key, value]) => key + ': ' + String(value)).join('\n') : 'Sin datos de cambio publicables';
}

/** Owner-only audit surface. Never reads tenant-internal audit events. */
export function PlatformAuditPanel() {
  const [draft, setDraft] = useState<Filters>(emptyFilters);
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [selected, setSelected] = useState<AuditRow | null>(null);

  useEffect(() => {
    const abort = new AbortController();
    async function load() {
      setState({ status: 'loading' });
      const params = new URLSearchParams({ page: String(page) });
      if (filters.actor) params.set('actor', filters.actor);
      if (filters.action) params.set('action', filters.action);
      if (filters.type) params.set('type', filters.type);
      if (filters.from) params.set('from', filters.from);
      if (filters.through) params.set('through', filters.through);
      try {
        const response = await fetch('/adminpl0n3r/api/auditoria?' + params.toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal: abort.signal,
        });
        if (abort.signal.aborted) return;
        if (response.status === 401 || response.status === 403) {
          setState({ status: 'denied' });
          return;
        }
        if (!response.ok) {
          setState({ status: 'error' });
          return;
        }
        const data = await response.json() as AuditPage;
        if (!abort.signal.aborted) setState({ status: 'ready', data });
      } catch {
        if (!abort.signal.aborted) setState({ status: 'error' });
      }
    }
    void load();
    return () => abort.abort();
  }, [filters, page, revision]);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSelected(null);
    setPage(1);
    setFilters({ ...draft });
  }

  const data = state.status === 'ready' ? state.data : null;
  return (
    <section aria-label="Auditoría de plataforma">
      <PageHeader title="Auditoría de plataforma" description="Consulta los cambios de configuración de la plataforma sin acceder a datos internos de las empresas." actions={
        <button className="button button-secondary" type="button" onClick={() => setRevision(value => value + 1)}>Actualizar</button>
      } />
      <MetricStrip label="Resumen de auditoría" items={[
        { label: 'Eventos encontrados', value: data?.total ?? '—' },
        { label: 'Página', value: data?.page ?? page },
      ]} />
      <form onSubmit={submit}>
        <Toolbar>
          <label className="field"><span>Actor (identificador)</span>
            <input value={draft.actor} maxLength={64} onChange={e => setDraft(v => ({ ...v, actor: e.target.value }))} />
          </label>
          <label className="field"><span>Tipo de acción</span>
            <input value={draft.action} maxLength={120} onChange={e => setDraft(v => ({ ...v, action: e.target.value }))} />
          </label>
          <label className="field"><span>Tipo de objeto</span>
            <input value={draft.type} maxLength={160} onChange={e => setDraft(v => ({ ...v, type: e.target.value }))} />
          </label>
          <label className="field"><span>Desde</span>
            <input type="date" value={draft.from} onChange={e => setDraft(v => ({ ...v, from: e.target.value }))} />
          </label>
          <label className="field"><span>Hasta</span>
            <input type="date" value={draft.through} onChange={e => setDraft(v => ({ ...v, through: e.target.value }))} />
          </label>
          <button className="button" type="submit">Filtrar</button>
        </Toolbar>
      </form>
      {state.status === 'loading' && <p role="status">Cargando auditoría…</p>}
      {state.status === 'denied' && <p role="alert">No tienes permiso para consultar la auditoría.</p>}
      {state.status === 'error' && <p role="alert">No fue posible cargar la auditoría. <button type="button" onClick={() => setRevision(v => v + 1)}>Reintentar</button></p>}
      {data && <>
        <DataTable
          caption="Eventos de auditoría de plataforma"
          rows={data.items}
          rowKey={item => item.id}
          columns={[
            { key: 'date', header: 'Fecha UTC', render: item => readableDate(item.created_at) },
            { key: 'actor', header: 'Actor', render: item => item.actor },
            { key: 'action', header: 'Acción', render: item => item.action },
            { key: 'object', header: 'Objeto', render: item => item.object.type },
            { key: 'status', header: 'Estado', render: () => <StatusPill tone="info">Registrado</StatusPill> },
          ]}
          onOpen={item => setSelected(item)}
          empty={<p>No hay cambios que coincidan con los filtros.</p>}
        />
        <nav aria-label="Páginas de auditoría">
          <button type="button" disabled={page <= 1} onClick={() => { setSelected(null); setPage(v => v - 1); }}>Anterior</button>
          <span> Página {page} </span>
          <button type="button" disabled={!data.has_next} onClick={() => { setSelected(null); setPage(v => v + 1); }}>Siguiente</button>
        </nav>
      </>}
      <SidePanel open={selected !== null} title="Detalle del cambio" onClose={() => setSelected(null)}>
        {selected && <dl>
          <dt>Fecha UTC</dt><dd>{readableDate(selected.created_at)}</dd>
          <dt>Actor</dt><dd>{selected.actor}</dd>
          <dt>Acción</dt><dd>{selected.action}</dd>
          <dt>Objeto</dt><dd>{selected.object.type} · {selected.object.id}</dd>
          <dt>Antes</dt><dd><pre>{safeSnapshot(selected.before)}</pre></dd>
          <dt>Después</dt><dd><pre>{safeSnapshot(selected.after)}</pre></dd>
        </dl>}
      </SidePanel>
    </section>
  );
}
