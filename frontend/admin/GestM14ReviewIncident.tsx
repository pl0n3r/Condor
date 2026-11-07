import { useEffect, useState } from 'react';

type SyntheticKind = 'recertification' | 'incident';
type SyntheticItem = {
  id: string;
  kind: SyntheticKind;
  status: string;
  label: string;
  created_at: string;
};
type AuditRecord = {
  item_id: string;
  kind: SyntheticKind;
  status: string;
  event: string;
  actor_ref: string;
  at: string;
};
type ReadState = 'loading' | 'ready' | 'error' | 'permission-denied';

type Props = Readonly<{ branchId: string; csrfToken: string; canWrite: boolean }>;

function nextStatus(item: SyntheticItem): string | null {
  if (item.kind === 'recertification') {
    return item.status === 'pending' ? 'approved' : null;
  }
  if (item.status === 'open') return 'investigating';
  return item.status === 'investigating' ? 'resolved' : null;
}

export function GestM14ReviewIncident({ branchId, csrfToken, canWrite }: Props) {
  const [state, setState] = useState<ReadState>('loading');
  const [items, setItems] = useState<SyntheticItem[]>([]);
  const [audit, setAudit] = useState<AuditRecord[]>([]);
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const base = '/api/v1/branches/' + encodeURIComponent(branchId) + '/m14/synthetic';

  useEffect(() => {
    let active = true;
    const abort = new AbortController();
    setState('loading');
    fetch(base, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: abort.signal,
    }).then(async (response) => {
      if (response.status === 403) {
        if (active) setState('permission-denied');
        return;
      }
      if (!response.ok) throw new Error('No se pudo cargar la simulación');
      const result = await response.json() as {
        mode: string; items: SyntheticItem[]; audit: AuditRecord[];
      };
      if (result.mode !== 'synthetic') throw new Error('Modo inesperado');
      if (active) {
        setItems(result.items);
        setAudit(result.audit);
        setState('ready');
      }
    }).catch(() => { if (active) setState('error'); });
    return () => { active = false; abort.abort(); };
  }, [base, reload]);

  async function submit(kind: SyntheticKind, item?: SyntheticItem) {
    if (!canWrite || busy) return;
    const next = item ? nextStatus(item) : null;
    if (item && !next) return;
    setBusy(true);
    try {
      const response = await fetch(item ? base + '/' + encodeURIComponent(item.id) : base, {
        method: item ? 'PATCH' : 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify(item ? { status: next } : { kind }),
      });
      if (response.status === 403) {
        setState('permission-denied');
        return;
      }
      if (!response.ok) throw new Error('Error de guardado');
      setReload((value) => value + 1);
    } catch {
      setState('error');
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="access-panel" aria-label="M14 revisiones e incidentes sintéticos">
      <h3>M14 · Revisión y respuesta (simulación DEV)</h3>
      <p className="muted">Solo fixtures. No registra incidentes ni revisiones reales.</p>
      {state === 'loading' && <p role="status">Cargando simulación…</p>}
      {state === 'permission-denied' && (
        <p role="alert">No tienes permiso para consultar estas revisiones.</p>
      )}
      {state === 'error' && (
        <div role="alert">
          No fue posible consultar la simulación.
          <button type="button" onClick={() => setReload((value) => value + 1)}>
            Reintentar
          </button>
        </div>
      )}
      {state === 'ready' && (
        <>
          {canWrite && (
            <div className="role-actions">
              <button type="button" disabled={busy}
                onClick={() => void submit('recertification')}>
                Crear recertificación sintética
              </button>
              <button type="button" disabled={busy}
                onClick={() => void submit('incident')}>
                Crear incidente sintético
              </button>
            </div>
          )}
          {items.length === 0 && <p className="muted">No hay simulaciones registradas.</p>}
          <ul>
            {items.map((item) => (
              <li key={item.id}>
                <span>{item.label}: {item.status}</span>
                {canWrite && nextStatus(item) && (
                  <button type="button" disabled={busy}
                    onClick={() => void submit(item.kind, item)}>
                    Avanzar a {nextStatus(item)}
                  </button>
                )}
              </li>
            ))}
          </ul>
          <h4>Traza de auditoría sintética</h4>
          {audit.length === 0 && <p className="muted">Sin eventos.</p>}
          <ol>
            {audit.map((entry, index) => (
              <li key={entry.item_id + entry.event + index}>
                {entry.event} · {entry.status} · {entry.actor_ref}
              </li>
            ))}
          </ol>
        </>
      )}
    </section>
  );
}
