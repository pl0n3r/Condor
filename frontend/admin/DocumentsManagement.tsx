/// <reference types="vite/client" />
import { useState, type FormEvent, type ChangeEvent } from 'react';

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
}>;

/** documents.* no existe todavía en PermissionCatalog; por defecto DENY para toda operación. */
export function DocumentsManagement({ tenantId, branchId, legalEntityId, permissions }: Props) {
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
  }
  return <section id="documents" className="catalog-panel" aria-labelledby="documents-title">
    <div className="section-heading"><div>
      <span className="eyebrow">M08 · Solo desarrollo</span>
      <h2 id="documents-title">Gestión documental · simulador</h2>
      <p className="muted">Exclusivamente referencias ficticias. Sin API, archivos, auditoría oficial ni aprobación automática.</p>
    </div></div>
    {notice && <output aria-live="polite">{notice}</output>}
    <div className="production-layout">
      <section className="catalog-card" aria-labelledby="doc-list-title">
        <h3 id="doc-list-title">Expedientes ficticios</h3>
        <label className="field"><span>Filtrar por estado</span>
          <select value={filter} onChange={(event: ChangeEvent<HTMLSelectElement>) => setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="BORRADOR">Borrador</option>
            <option value="PENDIENTE_REVISION">Pendiente de revisión manual</option>
          </select>
        </label>
        {rows.length === 0 ? <p role="status">Sin expedientes ficticios para este filtro.</p>
          : <ul>{rows.map(doc => <li key={doc.reference}>
              <button type="button" onClick={() => send({ type: 'VIEW', reference: doc.reference })}>
                {doc.reference}
              </button>
              {' · '}{doc.classification}{' · '}{doc.status}
            </li>)}</ul>}
      </section>
      {canCreate && <section className="catalog-card" aria-labelledby="doc-new-title">
        <h3 id="doc-new-title">Nuevo expediente ficticio</h3>
        <form className="catalog-editor" onSubmit={create}>
          <label className="field"><span>Referencia de prueba</span>
            <input required maxLength={44} pattern="fixture:doc/[a-z][a-z0-9_-]{0,31}"
              placeholder="fixture:doc/demo" value={reference}
              onChange={(event: ChangeEvent<HTMLInputElement>) => setReference(event.target.value)} />
          </label>
          <label className="field"><span>Clasificación</span>
            <select value={classification} onChange={(event: ChangeEvent<HTMLSelectElement>) => setClassification(event.target.value as Classification)}>
              <option value="PUBLICA">Pública</option><option value="INTERNA">Interna</option>
              <option value="RESERVADA">Reservada</option>
            </select>
          </label>
          <button type="submit">Crear borrador ficticio</button>
        </form>
      </section>}
      <section className="catalog-card" aria-labelledby="doc-detail-title">
        <h3 id="doc-detail-title">Detalle</h3>
        {selected ? <>
          <p><strong>Referencia:</strong> {selected.reference}</p>
          <p><strong>Clasificación:</strong> {selected.classification}</p>
          <p><strong>Versión sintética:</strong> {selected.version}</p>
          <p><strong>Estado:</strong> {selected.status}</p>
          {canUpdate && selected.status === 'BORRADOR' &&
            <button type="button" onClick={() => send({ type: 'REQUEST_REVIEW', reference: selected.reference })}>
              Solicitar revisión manual
            </button>}
          <p>No hay aprobación, cierre ni borrado automático.</p>
        </> : <p role="status">Selecciona un expediente ficticio para consultar su detalle.</p>}
      </section>
      <section className="catalog-card" aria-labelledby="doc-audit-title">
        <h3 id="doc-audit-title">Bitácora de demostración (no oficial)</h3>
        <p>Los eventos son locales y efímeros; no sustituyen una auditoría de servidor.</p>
        {snapshot.events.length === 0 ? <p role="status">Aún no hay actividad ficticia.</p>
          : <ol>{snapshot.events.map((entry, index) => <li key={`${entry.reference}-${index}`}>
            {entry.action} · {entry.reference}
          </li>)}</ol>}
      </section>
    </div>
  </section>;
}