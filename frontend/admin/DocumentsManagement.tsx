/// <reference types="vite/client" />
import { useEffect, useState, type FormEvent, type ChangeEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

function documentLabel(reference: string): string {
  return reference.replace(/^fixture:doc\//, 'Expediente ');
}

/** M08: Prototipo de datos ficticios. Sin API, persistencia ni auditoría de servidor. */
export type Classification = 'PUBLICA' | 'INTERNA' | 'RESERVADA';
export type DocumentStatus = 'BORRADOR' | 'PENDIENTE_REVISION';
export type DocumentRecord = Readonly<{
  scope: string;
  reference: string;
  classification: Classification;
  version: 1;
  status: DocumentStatus;
}>;
export type DocumentAudit = Readonly<{
  scope: string;
  reference: string;
  action: 'CREAR' | 'CONSULTAR' | 'SOLICITAR_REVISION';
}>;
export type DocumentWorkspace = Readonly<{
  scope: string;
  documents: readonly DocumentRecord[];
  events: readonly DocumentAudit[];
  selected: string;
}>;
export type Permissions = Readonly<{ view: boolean; create: boolean; update: boolean }>;
export type DocumentAction =
  | Readonly<{ type: 'CREATE'; reference: string; classification: Classification }>
  | Readonly<{ type: 'VIEW'; reference: string }>
  | Readonly<{ type: 'REQUEST_REVIEW'; reference: string }>;

const syntheticReference = /^fixture:doc\/[a-z][a-z0-9_-]{0,31}$/;
const classifications: readonly Classification[] = ['PUBLICA', 'INTERNA', 'RESERVADA'];

export function isSyntheticDocumentReference(value: string): boolean {
  return syntheticReference.test(value);
}

/** Un scope ajeno jamás hereda las filas ni la bitácora de otro tenant. */
export function initialDocumentWorkspace(scope: string): DocumentWorkspace {
  return { scope, documents: [], events: [], selected: '' };
}

/** Proyección local, explícita y reversible. No equivale a registrar un AuditEvent oficial. */
export function applyDocumentAction(
  stored: DocumentWorkspace,
  currentScope: string,
  permissions: Permissions,
  action: DocumentAction,
): DocumentWorkspace {
  const state = stored.scope === currentScope ? stored : initialDocumentWorkspace(currentScope);
  if (!currentScope || !currentScope.includes('|')) return initialDocumentWorkspace('');
  if (!isSyntheticDocumentReference(action.reference)) return state;
  const original = state.documents.find((record) =>
    record.scope === currentScope && record.reference === action.reference);
  if (action.type === 'CREATE') {
    if (!permissions.create || !permissions.view || original
      || state.documents.length >= 100 || !classifications.includes(action.classification)) return state;
    const record: DocumentRecord = {
      scope: currentScope,
      reference: action.reference,
      classification: action.classification,
      version: 1,
      status: 'BORRADOR',
    };
    return {
      ...state,
      documents: [...state.documents, record],
      events: [...state.events, { scope: currentScope, reference: action.reference, action: 'CREAR' as const }].slice(-100),
    };
  }
  if (!original || !permissions.view) return state;
  if (action.type === 'VIEW') {
    return {
      ...state,
      selected: action.reference,
      events: [...state.events, { scope: currentScope, reference: action.reference, action: 'CONSULTAR' as const }].slice(-100),
    };
  }
  if (!permissions.update || original.status !== 'BORRADOR') return state;
  return {
    ...state,
    documents: state.documents.map((document) => document === original
      ? { ...document, status: 'PENDIENTE_REVISION' as const }
      : document),
    events: [...state.events, { scope: currentScope, reference: action.reference, action: 'SOLICITAR_REVISION' as const }].slice(-100),
  };
}

export function visibleSyntheticDocuments(
  stored: DocumentWorkspace,
  currentScope: string,
  canView: boolean,
  filter: 'TODOS' | DocumentStatus,
): readonly DocumentRecord[] {
  if (!canView || stored.scope !== currentScope) return [];
  return stored.documents.filter((document) => document.scope === currentScope
    && (filter === 'TODOS' || document.status === filter));
}

type Props = Readonly<{
  tenantId: string;
  branchId: string;
  legalEntityId: string;
  permissions: readonly string[];
  csrfToken?: string;
}>;

type RemoteDocument = Readonly<{
  reference: string; classification: Classification; status: DocumentStatus; evidence_ref?: string;
}>;
type RemoteState =
  | { kind: 'loading' | 'denied' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; documents: readonly RemoteDocument[] };

/** Server-authoritative M08 dev/test panel. All data and receipts are synthetic. */
function HttpDocumentsPanel({ branchId, permissions, csrfToken }: Props) {
  // Catalog does not yet define documents.*; dev/test HTTP uses restricted
  // settings.* permissions. Never infer a grant from the client-side checkbox.
  const canView = permissions.includes('settings.view');
  const canWrite = canView && permissions.includes('settings.update');
  const base = '/api/v1/branches/' + encodeURIComponent(branchId) + '/documents';
  const [state, setState] = useState<RemoteState>({ kind: 'loading' });
  const [reload, setReload] = useState(0);
  const [selected, setSelected] = useState('');
  const [detailOpen, setDetailOpen] = useState(false);
  const [httpSearch, setHttpSearch] = useState('');
  const [trace, setTrace] = useState<string[]>([]);
  const [evidence, setEvidence] = useState('fixture:evidence/demo');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  useEffect(() => {
    if (!canView) {
      setState({ kind: 'denied' });
      return;
    }
    const abort = new AbortController();
    setState({ kind: 'loading' });
    setMessage('');
    void fetch(base, {
      credentials: 'same-origin', signal: abort.signal, headers: { Accept: 'application/json' },
    }).then(async (response) => {
      if (response.status === 401 || response.status === 403) {
        if (!abort.signal.aborted) setState({ kind: 'denied' });
        return;
      }
      if (!response.ok) throw new Error('El servidor no permitió cargar documentos.');
      const body = await response.json() as {
        documents?: RemoteDocument[]; synthetic?: boolean; can_close?: boolean;
        signature_gate?: { status?: string; legal_signature?: boolean };
      };
      if (!Array.isArray(body.documents) || body.synthetic !== true || body.can_close !== false
        || body.signature_gate?.status !== 'NO_VERIFICABLE'
        || body.signature_gate?.legal_signature !== false) {
        throw new Error('Respuesta documental sin garantías de simulación.');
      }
      if (!abort.signal.aborted) setState({ kind: 'ready', documents: body.documents });
    }).catch(() => {
      if (!abort.signal.aborted) setState({ kind: 'error', message: 'Error al consultar M08.' });
    });
    return () => abort.abort();
  }, [base, canView, reload]);

  function keyOf(reference: string): string | null {
    return /^fixture:doc\/[a-z][a-z0-9_-]{0,31}$/.test(reference)
      ? reference.slice('fixture:doc/'.length) : null;
  }
  async function showTrace(reference: string) {
    const key = keyOf(reference);
    if (!canView || key === null) return;
    setSelected(reference);
    setTrace([]);
    setMessage('');
    try {
      const response = await fetch(base + '/' + encodeURIComponent(key) + '/trace',
        { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401 || response.status === 403) {
        setState({ kind: 'denied' }); return;
      }
      if (!response.ok) throw new Error('No se encontró el historial.');
      const result = await response.json() as {
        synthetic?: boolean; can_close?: boolean;
        events?: { sequence: number; action: string }[];
      };
      if (result.synthetic !== true || result.can_close !== false || !Array.isArray(result.events)) {
        throw new Error('Historial sin garantías.');
      }
      setTrace(result.events.map((item) => item.sequence + '. ' + item.action));
    } catch {
      setMessage('Error al consultar la trazabilidad sintética.');
    }
  }
  async function attachEvidence() {
    const key = keyOf(selected);
    if (!canWrite || key === null || !/^fixture:evidence\/[a-z][a-z0-9_-]{0,47}$/.test(evidence)
      || busy) return;
    const token = csrfToken
      ?? document.getElementById('condor-admin-root')?.getAttribute('data-access-token') ?? '';
    if (!token) { setMessage('Token de sesión requerido.'); return; }
    setBusy(true);
    setMessage('');
    try {
      const response = await fetch(base + '/' + encodeURIComponent(key) + '/evidence', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ evidence_ref: evidence }),
      });
      if (response.status === 401 || response.status === 403) {
        setState({ kind: 'denied' }); return;
      }
      if (!response.ok) throw new Error('Evidencia rechazada.');
      const result = await response.json() as { synthetic?: boolean; legal_signature?: boolean };
      if (result.synthetic !== true || result.legal_signature !== false) {
        throw new Error('Respuesta de evidencia inválida.');
      }
      setMessage('Referencia ficticia recibida; no es firma ni evidencia legal.');
      setReload((value) => value + 1);
    } catch {
      setMessage('Error al adjuntar evidencia ficticia.');
    } finally {
      setBusy(false);
    }
  }

  const filtered = state.kind === 'ready'
    ? state.documents.filter((doc) =>
      (documentLabel(doc.reference) + ' ' + doc.classification + ' ' + doc.status)
        .toLocaleLowerCase('es-CO').includes(httpSearch.toLocaleLowerCase('es-CO'))) : [];
  return <section className="ui-page" aria-label="Gestión documental HTTP de prueba">
    <PageHeader title="Expedientes de desarrollo" description="Datos ficticios de sesión, sin firma válida, cierre ni auditoría jurídica persistente."
      actions={state.kind === 'ready' ?
        <a className="button button-secondary" href={base + '/export'}>Exportar metadatos de prueba</a> : undefined} />
    {state.kind === 'loading' && <output role="status" aria-live="polite">Cargando documentos…</output>}
    {state.kind === 'denied' && <p role="alert">Permiso denegado para documentos.</p>}
    {state.kind === 'error' && <div className="alert alert-error" role="alert">{state.message}
      <button type="button" className="button button-secondary" onClick={() => setReload((n) => n + 1)}>Reintentar</button>
    </div>}
    {state.kind === 'ready' && <>
      <MetricStrip label="Expedientes HTTP ficticios" items={[
        { label: 'Expedientes', value: state.documents.length },
        { label: 'Borradores', value: state.documents.filter((doc) => doc.status === 'BORRADOR').length },
        { label: 'En revisión', value: state.documents.filter((doc) => doc.status === 'PENDIENTE_REVISION').length },
      ]} />
      <Card title="Expedientes de prueba">
        <Toolbar trailing={<span className="ui-count">{filtered.length} de {state.documents.length}</span>}>
          <label className="ui-search"><span className="ui-sr-only">Buscar expediente de prueba</span>
            <input type="search" placeholder="Buscar expediente, clasificación o estado"
              value={httpSearch} onChange={(event) => setHttpSearch(event.target.value)} />
          </label>
        </Toolbar>
        <DataTable caption="Expedientes de la sesión de prueba" rows={filtered}
          rowKey={(doc) => doc.reference}
          onOpen={(doc) => { setDetailOpen(true); void showTrace(doc.reference); }}
          empty={<><strong>Sin expedientes en la sesión</strong><span>Prueba otra búsqueda o utiliza el simulador documental.</span></>}
          columns={[
            { key: 'reference', header: 'Expediente', render: (doc) => documentLabel(doc.reference) },
            { key: 'classification', header: 'Clasificación', render: (doc) => doc.classification },
            { key: 'status', header: 'Estado', render: (doc) =>
              <StatusPill tone={doc.status === 'BORRADOR' ? 'neutral' : 'warn'}>
                {doc.status === 'BORRADOR' ? 'Borrador' : 'Pendiente de revisión'}</StatusPill> },
          ]} />
      </Card>
    </>}
    {message && <output aria-live="polite">{message}</output>}
    <SidePanel open={detailOpen && selected !== ''} title={'Detalle de ' + documentLabel(selected)}
      onClose={() => { setDetailOpen(false); setSelected(''); }}>
      <p>Referencia: <strong>{documentLabel(selected)}</strong></p>
      {trace.length === 0 ? <p role="status">Sin traza cargada.</p>
        : <ol>{trace.map((entry) => <li key={entry}>{entry}</li>)}</ol>}
      {canWrite && <>
        <label className="field">Referencia de evidencia ficticia
          <input value={evidence} onChange={(event) => setEvidence(event.target.value)} />
        </label>
        <button type="button" className="button" disabled={busy}
          onClick={() => void attachEvidence()}>Adjuntar evidencia ficticia</button>
      </>}
    </SidePanel>
  </section>;
}

/** Combined M08 entrypoint; server paths are never called in production. */
export function DocumentsManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <>
    <HttpDocumentsPanel key={props.branchId} {...props} />
    {props.permissions.includes('documents.view') && <LocalDocumentsPreview {...props} />}
  </>;
}

/** Local reducer is retained for backwards-compatible synthetic tests only. */
function LocalDocumentsPreview({ tenantId, branchId, legalEntityId, permissions }: Props) {
  const scope = `${tenantId}|${legalEntityId}|${branchId}`;
  const canView = permissions.includes('documents.view');
  const canCreate = canView && permissions.includes('documents.create');
  const canUpdate = canView && permissions.includes('documents.update');
  const grants: Permissions = { view: canView, create: canCreate, update: canUpdate };
  const [workspace, setWorkspace] = useState<DocumentWorkspace>(() => initialDocumentWorkspace(scope));
  const [reference, setReference] = useState('');
  const [classification, setClassification] = useState<Classification>('INTERNA');
  const [filter, setFilter] = useState<'TODOS' | DocumentStatus>('TODOS');
  const [notice, setNotice] = useState('');
  const [creating, setCreating] = useState(false);
  const [localDetailOpen, setLocalDetailOpen] = useState(false);
  const [localSearch, setLocalSearch] = useState('');
  if (!import.meta.env.DEV) return null;
  if (!canView) return <section id="documents" aria-label="Gestión documental · simulación">
    <p role="alert">Sin permiso documental. Simulador deshabilitado.</p>
  </section>;

  const snapshot = workspace.scope === scope ? workspace : initialDocumentWorkspace(scope);
  const rows = visibleSyntheticDocuments(snapshot, scope, canView, filter);
  const selected = rows.find((doc) => doc.reference === snapshot.selected)
    ?? visibleSyntheticDocuments(snapshot, scope, canView, 'TODOS').find((doc) => doc.reference === snapshot.selected);

  function send(action: DocumentAction) {
    setWorkspace(previous => applyDocumentAction(previous, scope, grants, action));
  }
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !isSyntheticDocumentReference(reference)) {
      setNotice('Solo se admiten referencias ficticias, como fixture:doc/demo.');
      return;
    }
    if (snapshot.documents.some(doc => doc.reference === reference)) {
      setNotice('Ya existe esa referencia de prueba en esta empresa.');
      return;
    }
    send({ type: 'CREATE', reference, classification });
    setReference('');
    setNotice('Borrador ficticio registrado. No se ha guardado en el servidor.');
    setCreating(false);
  }
  const visible = rows.filter((doc) =>
    (documentLabel(doc.reference) + ' ' + doc.classification + ' ' + doc.status)
      .toLocaleLowerCase('es-CO').includes(localSearch.toLocaleLowerCase('es-CO')));
  return <section id="documents" className="ui-page" aria-label="Gestión documental · simulador">
    <PageHeader title="Gestión documental · simulador"
      description="Expedientes ficticios de desarrollo. Sin API, archivos reales ni auditoría oficial."
      actions={canCreate ? <button type="button" className="button"
        onClick={() => setCreating(true)}>Nuevo expediente ficticio</button> : undefined} />
    {notice && <output aria-live="polite">{notice}</output>}
    <MetricStrip label="Resumen documental sintético" items={[
      { label: 'Expedientes', value: snapshot.documents.length },
      { label: 'Borradores', value: snapshot.documents.filter((doc) => doc.status === 'BORRADOR').length },
      { label: 'Pendientes de revisión', value: snapshot.documents.filter((doc) => doc.status === 'PENDIENTE_REVISION').length },
    ]} />
    <Card title="Expedientes ficticios">
      <Toolbar trailing={<span className="ui-count">{visible.length} de {rows.length}</span>}>
        <label className="ui-search"><span className="ui-sr-only">Buscar expediente</span>
          <input type="search" placeholder="Buscar expediente o clasificación"
            value={localSearch} onChange={(event) => setLocalSearch(event.target.value)} />
        </label>
        <label className="field"><span>Estado</span>
          <select value={filter} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
            setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="BORRADOR">Borrador</option>
            <option value="PENDIENTE_REVISION">Pendiente de revisión manual</option>
          </select>
        </label>
      </Toolbar>
      <DataTable caption="Expedientes ficticios de la empresa" rows={visible}
        rowKey={(doc) => doc.reference}
        onOpen={(doc) => { send({ type: 'VIEW', reference: doc.reference }); setLocalDetailOpen(true); }}
        empty={<><strong>Sin expedientes para mostrar</strong><span>Quita los filtros o crea un borrador ficticio.</span></>}
        columns={[
          { key: 'reference', header: 'Expediente', render: (doc) => documentLabel(doc.reference) },
          { key: 'class', header: 'Clasificación', render: (doc) => doc.classification },
          { key: 'state', header: 'Estado', render: (doc) =>
            <StatusPill tone={doc.status === 'BORRADOR' ? 'neutral' : 'warn'}>
              {doc.status === 'BORRADOR' ? 'Borrador' : 'Pendiente de revisión'}</StatusPill> },
          { key: 'version', header: 'Versión', render: (doc) => doc.version, align: 'right' },
        ]} />
    </Card>
    <Card title="Bitácora de demostración">
      <p>Los eventos son locales y efímeros; no sustituyen una auditoría del servidor.</p>
      {snapshot.events.length === 0 ? <p role="status">Aún no hay actividad ficticia.</p>
        : <ol>{snapshot.events.map((entry, index) => <li key={entry.reference + index}>
          {entry.action} · {documentLabel(entry.reference)}
        </li>)}</ol>}
    </Card>
    <SidePanel open={creating && canCreate} title="Nuevo expediente ficticio"
      onClose={() => setCreating(false)}>
      <form className="ui-form" onSubmit={create}>
        <label className="field">Referencia de prueba
          <input required maxLength={44} pattern="fixture:doc/[a-z][a-z0-9_-]{0,31}"
            placeholder="fixture:doc/demo" value={reference}
            onChange={(event: ChangeEvent<HTMLInputElement>) => setReference(event.target.value)} />
        </label>
        <label className="field">Clasificación
          <select value={classification} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
            setClassification(event.target.value as Classification)}>
            <option value="PUBLICA">Pública</option>
            <option value="INTERNA">Interna</option>
            <option value="RESERVADA">Reservada</option>
          </select>
        </label>
        <p className="muted">El expediente se creará solo en la memoria de esta página de pruebas.</p>
        <button type="submit" className="button">Crear borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={localDetailOpen && selected !== undefined}
      title={'Detalle de ' + (selected ? documentLabel(selected.reference) : 'expediente')}
      onClose={() => setLocalDetailOpen(false)}>
      {selected && <dl className="ui-details">
        <div><dt>Referencia</dt><dd>{documentLabel(selected.reference)}</dd></div>
        <div><dt>Clasificación</dt><dd>{selected.classification}</dd></div>
        <div><dt>Versión sintética</dt><dd>{selected.version}</dd></div>
        <div><dt>Estado</dt><dd><StatusPill tone={selected.status === 'BORRADOR' ? 'neutral' : 'warn'}>
          {selected.status === 'BORRADOR' ? 'Borrador' : 'Pendiente de revisión'}</StatusPill></dd></div>
      </dl>}
      {selected && canUpdate && selected.status === 'BORRADOR' &&
        <button type="button" className="button" onClick={() =>
          send({ type: 'REQUEST_REVIEW', reference: selected.reference })}>Solicitar revisión manual</button>}
      <p className="muted">No hay aprobación, cierre ni borrado automático.</p>
    </SidePanel>
  </section>;
}
