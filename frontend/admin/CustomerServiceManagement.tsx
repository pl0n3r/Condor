/// <reference types="vite/client" />
import { useState, type ChangeEvent, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

/** M16 synthetic-only simulator. Not an API, persistent store or official audit. */
type CaseState = 'REQUIERE_REVISION' | 'EN_REVISION';
type CaseKind = 'PQRS' | 'GARANTIA' | 'SOLICITUD';
type CaseRecord = Readonly<{ ref: string; kind: CaseKind; state: CaseState }>;
type Audit = Readonly<{ scope: string; ref: string; operation: 'created' | 'viewed' | 'state_changed' }>;
type Props = Readonly<{ tenantId: string; branchId: string; permissions: string[] }>;
const syntheticRef = /^fixture:case\/[a-z][a-z0-9_-]{0,31}$/;
const kinds: CaseKind[] = ['PQRS', 'GARANTIA', 'SOLICITUD'];

function Workspace({ tenantId, branchId, permissions }: Props) {
  const canView = permissions.includes('inventory.view');
  const canCreate = canView && permissions.includes('inventory.create');
  const canUpdate = canView && permissions.includes('inventory.update');
  const [records, setRecords] = useState<CaseRecord[]>([]);
  const [events, setEvents] = useState<Audit[]>([]);
  const [selected, setSelected] = useState('');
  const [filter, setFilter] = useState<'TODOS' | CaseState>('TODOS');
  const [ref, setRef] = useState('');
  const [kind, setKind] = useState<CaseKind>('PQRS');
  const [notice, setNotice] = useState('');
  const [creating, setCreating] = useState(false);
  const scope = tenantId + '/' + branchId;
  function log(refId: string, operation: Audit['operation']) {
    setEvents(current => [...current, { scope, ref: refId, operation }].slice(-100));
  }
  function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || !syntheticRef.test(ref) || !kinds.includes(kind)
      || records.length >= 100 || records.some(r => r.ref === ref)) {
      setNotice('Solo se admiten referencias de prueba nuevas, como fixture:case/demo.');
      return;
    }
    setRecords(current => [...current, { ref, kind, state: 'REQUIERE_REVISION' }]);
    log(ref, 'created');
    setRef('');
    setNotice('Caso ficticio creado. Pendiente de revisión.');
    setCreating(false);
  }
  function view(reference: string) {
    if (!canView || !syntheticRef.test(reference) || !records.some(r => r.ref === reference)) return;
    setSelected(reference);
    log(reference, 'viewed');
  }
  function moveToReview(reference: string) {
    if (!canUpdate || !syntheticRef.test(reference)
      || !records.some(r => r.ref === reference && r.state === 'REQUIERE_REVISION')) return;
    setRecords(current => current.map(r => r.ref === reference ? { ...r, state: 'EN_REVISION' } : r));
    log(reference, 'state_changed');
    setNotice('Transición de prueba registrada para revisión humana.');
  }
  if (!canView) return <section id="customer-service" className="ui-page">
    <PageHeader title="Servicio al cliente" description="Casos y solicitudes de la sede activa." />
    <p role="alert">No tienes permiso para consultar el servicio al cliente.</p>
  </section>;
  const shown = records.filter(r => filter === 'TODOS' || r.state === filter);
  const detail = records.find(r => r.ref === selected);
  const statusTone = (state: CaseState) => state === 'EN_REVISION' ? 'info' as const : 'warn' as const;
  return <section id="customer-service" className="ui-page" aria-label="Servicio al cliente">
    <PageHeader title="Servicio al cliente"
      description="Simulador local sin datos reales, API, persistencia ni auditoría de servidor."
      actions={canCreate && <button type="button" className="button"
        onClick={() => setCreating(true)}>Nuevo caso de prueba</button>} />
    <MetricStrip label="Estado de casos de prueba" items={[
      { label: 'Casos', value: records.length },
      { label: 'Pendientes', value: records.filter(r => r.state === 'REQUIERE_REVISION').length, tone: 'warn' },
      { label: 'En revisión', value: records.filter(r => r.state === 'EN_REVISION').length, tone: 'info' },
    ]} />
    {notice && <output aria-live="polite">{notice}</output>}
    <Card title="Casos de prueba">
      <Toolbar trailing={<span className="ui-count">{shown.length} resultados</span>}>
        <label>Filtrar por estado
          <select value={filter} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
            setFilter(event.target.value as typeof filter)}>
            <option value="TODOS">Todos</option>
            <option value="REQUIERE_REVISION">Requiere revisión</option>
            <option value="EN_REVISION">En revisión</option>
          </select>
        </label>
      </Toolbar>
      <DataTable caption="Casos ficticios de servicio al cliente"
        rows={shown} rowKey={r => r.ref} onOpen={r => view(r.ref)}
        empty={<p role="status">Sin casos ficticios.</p>}
        columns={[
          { key: 'ref', header: 'Caso', render: r => r.ref.replace('fixture:case/', '') },
          { key: 'type', header: 'Tipo', render: r => r.kind },
          { key: 'state', header: 'Estado', render: r => <StatusPill tone={statusTone(r.state)}>
            {r.state === 'EN_REVISION' ? 'En revisión' : 'Requiere revisión'}
          </StatusPill> },
        ]} />
    </Card>
    <Card title="Bitácora de demostración (no oficial)">
      {events.length === 0 ? <p role="status">Sin operaciones de prueba.</p> :
        <ul>{events.filter(e => e.scope === scope).map((entry, i) =>
          <li key={i}>{entry.operation}{' · '}{entry.ref.replace('fixture:case/', '')}</li>)}</ul>}
    </Card>
    <SidePanel open={creating && canCreate} title="Nuevo caso ficticio" onClose={() => setCreating(false)}>
      <form className="catalog-editor" onSubmit={create}>
        <label className="field"><span>Referencia sintética</span>
          <input required maxLength={45} placeholder="fixture:case/demo"
            pattern="fixture:case/[a-z][a-z0-9_-]{0,31}"
            value={ref} onChange={(event: ChangeEvent<HTMLInputElement>) => setRef(event.target.value)} />
        </label>
        <label className="field"><span>Tipo</span>
          <select value={kind} onChange={(event: ChangeEvent<HTMLSelectElement>) =>
            setKind(event.target.value as CaseKind)}>
            <option value="PQRS">PQRS</option><option value="GARANTIA">Garantía</option>
            <option value="SOLICITUD">Solicitud</option>
          </select>
        </label>
        <button className="button" type="submit">Crear caso de prueba</button>
      </form>
    </SidePanel>
    <SidePanel open={Boolean(detail)} title="Detalle y estado" onClose={() => setSelected('')}>
      {detail && <>
        <dl>
          <dt>Referencia</dt><dd>{detail.ref.replace('fixture:case/', '')}</dd>
          <dt>Tipo</dt><dd>{detail.kind}</dd>
          <dt>Estado</dt><dd><StatusPill tone={statusTone(detail.state)}>
            {detail.state === 'EN_REVISION' ? 'En revisión' : 'Requiere revisión'}
          </StatusPill></dd>
        </dl>
        {canUpdate && detail.state === 'REQUIERE_REVISION' &&
          <button type="button" className="button" onClick={() => moveToReview(detail.ref)}>
            Pasar explícitamente a revisión
          </button>}
        <p className="muted">No se permiten aprobaciones ni cierres automáticos.</p>
      </>}
    </SidePanel>
  </section>;
}

/** In production even a direct import of this component is inert. */
export function CustomerServiceManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Workspace key={props.tenantId + '|' + props.branchId} {...props} />;
}
