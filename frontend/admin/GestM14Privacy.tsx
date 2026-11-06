import { useEffect, useRef, useState, type FormEvent } from 'react';

/** M14-S08: synthetic-only. No contact details, mail, providers or legal acknowledgement. */
type Row = Readonly<{ id: string; kind: string; subject_ref: string; status: 'pending_review' }>;
type State = 'loading' | 'empty' | 'ready' | 'error' | 'denied';
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;
const types = { access: 'Consulta', rectification: 'Rectificación', deletion: 'Eliminación' } as const;
const valid = (x: unknown): x is Row => {
  if (x === null || typeof x !== 'object') return false;
  const r = x as Record<string, unknown>;
  return typeof r.id === 'string' && /^[a-f0-9]{16}$/.test(r.id)
    && typeof r.kind === 'string' && Object.hasOwn(types, r.kind)
    && typeof r.subject_ref === 'string' && /^fixture:subject\/[a-z][a-z0-9_-]{0,31}$/.test(r.subject_ref)
    && r.status === 'pending_review';
};

export function GestM14Privacy({ branchId, permissions, csrfToken }: Props) {
  const [state, setState] = useState<State>('loading');
  const [rows, setRows] = useState<Row[]>([]);
  const [selected, setSelected] = useState<Row | null>(null);
  const [kind, setKind] = useState<keyof typeof types>('access');
  const [subject, setSubject] = useState('fixture:subject/demo');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const sequence = useRef(0);
  const endpoint = '/api/v1/branches/' + encodeURIComponent(branchId) + '/privacy/requests';
  const allowed = permissions.includes('settings.view');
  const canCreate = allowed && permissions.includes('settings.create');
  useEffect(() => {
    const generation = ++sequence.current;
    const controller = new AbortController();
    setRows([]); setSelected(null); setNotice(''); setBusy(false);
    setState(allowed ? 'loading' : 'denied');
    if (allowed) {
      void (async () => {
        try {
          const response = await fetch(endpoint, { credentials: 'same-origin',
            headers: { Accept: 'application/json' }, signal: controller.signal });
          if (controller.signal.aborted || generation !== sequence.current) return;
          if (response.status === 401 || response.status === 403) { setState('denied'); return; }
          if (!response.ok) { setState('error'); return; }
          const data: unknown = await response.json();
          if (controller.signal.aborted || generation !== sequence.current) return;
          const body = data as { requests?: unknown; synthetic_only?: boolean };
          if (!body || body.synthetic_only !== true || !Array.isArray(body.requests)
            || body.requests.length > 24 || !body.requests.every(valid)) throw Error('Invalid synthetic data');
          setRows(body.requests);
          setState(body.requests.length ? 'ready' : 'empty');
        } catch {
          if (!controller.signal.aborted && generation === sequence.current) setState('error');
        }
      })();
    }
    return () => { sequence.current++; controller.abort(); };
  }, [branchId, allowed, endpoint]);
  async function create(event: FormEvent) {
    event.preventDefault();
    if (!canCreate || busy || !csrfToken || !/^fixture:subject\/[a-z][a-z0-9_-]{0,31}$/.test(subject)) return;
    const generation = sequence.current;
    setBusy(true); setNotice('');
    try {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ kind, subject_ref: subject }) });
      if (generation !== sequence.current) return;
      if (response.status === 401 || response.status === 403) { setState('denied'); setRows([]); return; }
      if (response.status !== 201) { setNotice('El servidor rechazó la solicitud ficticia.'); return; }
      const result: unknown = await response.json();
      const data = result as { request?: unknown; synthetic_only?: boolean; persisted?: boolean; dispatched?: boolean };
      if (!data || !valid(data.request) || data.synthetic_only !== true
        || data.persisted !== false || data.dispatched !== false) throw Error('Invalid receipt');
      const row = data.request;
      setRows(previous => [...previous, row]);
      setSelected(row);
      setState('ready');
      setNotice('Solicitud ficticia creada en sesión. No se envió ni almacenó legalmente.');
    } catch {
      if (generation === sequence.current) setNotice('Error de conexión. Verifica el estado antes de reintentar.');
    } finally {
      if (generation === sequence.current) setBusy(false);
    }
  }

  async function consult(row: Row) {
    if (!allowed) return;
    const generation = sequence.current;
    setSelected(null); setNotice('Consultando referencia ficticia…');
    try {
      const response = await fetch(endpoint + '/' + encodeURIComponent(row.id), {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      if (generation !== sequence.current) return;
      if (response.status === 401 || response.status === 403) { setState('denied'); setRows([]); return; }
      if (!response.ok) throw Error('Unavailable');
      const result: unknown = await response.json();
      const data = result as { request?: unknown; synthetic_only?: boolean };
      if (!data || data.synthetic_only !== true || !valid(data.request) || data.request.id !== row.id)
        throw Error('Invalid response');
      setSelected(data.request); setNotice('');
    } catch {
      if (generation === sequence.current) setNotice('No fue posible consultar la solicitud ficticia.');
    }
  }

  return <section aria-label="Privacidad M14 · demostración sintética">
    <h2>Privacidad · gestión M14</h2>
    <p role="note">SOLO PRUEBAS FICTICIAS: sin datos personales, correo ni validez legal.</p>
    {state === 'loading' && <p role="status">Cargando solicitudes…</p>}
    {state === 'denied' && <p role="alert">Permiso denegado para consultar privacidad.</p>}
    {state === 'error' && <p role="alert">No se pudieron cargar las solicitudes.</p>}
    {state === 'empty' && <p role="status">No existen solicitudes ficticias.</p>}
    {state === 'ready' && <ul>{rows.map(row => <li key={row.id}><button type="button"
      onClick={() => void consult(row)}>{types[row.kind as keyof typeof types]} · {row.subject_ref}</button></li>)}</ul>}
    {selected && <p role="status">Referencia {selected.id} · pendiente de revisión; sin cierre legal.</p>}
    {notice && <p role="status" aria-live="polite">{notice}</p>}
    {canCreate && (state === 'ready' || state === 'empty') && <form onSubmit={event => void create(event)}>
      <label>Derecho ficticio <select value={kind} onChange={event => setKind(event.target.value as keyof typeof types)}>
        {Object.entries(types).map(([key, label]) => <option value={key} key={key}>{label}</option>)}
      </select></label>
      <label>Sujeto ficticio <select value={subject} onChange={event => setSubject(event.target.value)}>
        <option value="fixture:subject/demo">Caso demo</option><option value="fixture:subject/revision">Caso revisión</option>
      </select></label>
      <button type="submit" disabled={busy}>{busy ? 'Registrando…' : 'Crear prueba sintética'}</button>
    </form>}
  </section>;
}
