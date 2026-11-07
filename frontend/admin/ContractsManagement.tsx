/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

// M02: demostración local, nunca un contrato de trabajo ejecutable ni una firma.
type Phase = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type ContractType = 'PLANTILLA' | 'CONTRATO' | 'OTROSI';
type Draft = Readonly<{
  ref: string;
  fictionalPerson: string;
  sourceTemplate: string;
  category: ContractType;
  phase: Phase;
  scope: string;
}>;
type Note = Readonly<{ id: number; scope: string; ref: string; operation: string }>;
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

// Fijar referencias sintéticas predecibles; una cadena "fixture:" no basta.
const permitted = {
  contract: new RegExp('^fixture:contracts/(demo|test|sample)-[0-9]{2,3}$'),
  person: new RegExp('^fixture:person/(demo|test|sample)-[0-9]{2,3}$'),
  template: new RegExp('^fixture:template/(demo|test|sample)-[0-9]{2,3}$'),
} as const;
const allowedType: readonly ContractType[] = ['PLANTILLA', 'CONTRATO', 'OTROSI'];

function ContractPreview({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = [tenantId, companyId, branchId].join('|');
  const canRead = companyId !== '__unassigned__' && permissions.includes('users.view');
  const canAdd = canRead && permissions.includes('users.create');
  const canAdvance = canRead && permissions.includes('users.update');
  const [drafts, setDrafts] = useState<Draft[]>([]);
  const [trail, setTrail] = useState<Note[]>([]);
  const [ref, setRef] = useState('');
  const [fictionalPerson, setFictionalPerson] = useState('');
  const [sourceTemplate, setSourceTemplate] = useState('');
  const [category, setCategory] = useState<ContractType>('CONTRATO');
  const [picked, setPicked] = useState<string | null>(null);
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
  const [filter, setFilter] = useState('');
  const [notice, setNotice] = useState('');

  const scopedDrafts = drafts.filter(entry => entry.scope === scope);
  const listing = scopedDrafts.filter(entry => filter === '' || entry.ref === filter);
  const selected = scopedDrafts.find(entry => entry.ref === picked);

  function record(refToRecord: string, operation: string) {
    setTrail(items => items.concat({ id: (items[items.length - 1]?.id ?? 0) + 1,
      scope, ref: refToRecord, operation }).slice(-80));
  }

  function createDemo(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const valid = permitted.contract.test(ref) && permitted.person.test(fictionalPerson)
      && permitted.template.test(sourceTemplate) && allowedType.includes(category);
    if (!canAdd || !valid || scopedDrafts.length >= 80
      || scopedDrafts.some(item => item.ref === ref)) {
      setNotice('Rechazado. Usa solo referencias inventadas, con permiso y sin duplicados.');
      return;
    }
    setDrafts(items => items.concat({
      ref, fictionalPerson, sourceTemplate, category, scope, phase: 'BORRADOR',
    }));
    record(ref, 'borrador_creado');
    setPicked(ref); setPanel('detail');
    setRef('');
    setFictionalPerson('');
    setSourceTemplate('');
    setNotice('Borrador ficticio creado en esta pantalla. Sin valor legal.');
  }

  function inspect(item: Draft) {
    if (!canRead || item.scope !== scope) return;
    setPicked(item.ref); setPanel('detail');
    record(item.ref, 'consulta_ficticia');
  }

  function advance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canAdvance || !selected || selected.scope !== scope || selected.phase === 'EN_REVISION') {
      setNotice('No es posible cambiar el estado con los permisos y el borrador actuales.');
      return;
    }
    const next: Phase = selected.phase === 'BORRADOR' ? 'REQUIERE_REVISION' : 'EN_REVISION';
    setDrafts(items => items.map(item => item.ref === selected.ref && item.scope === scope
      ? { ...item, phase: next } : item));
    record(selected.ref, 'revision_manual_' + next.toLowerCase());
    setNotice('Se registró una transición de prueba, no una firma ni un cierre.');
  }

  if (!canRead) return <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación ficticia">
    <PageHeader title="Contratación laboral · prueba local M02" />
    <p role="alert">No tienes permiso de lectura de la demostración o no hay empresa seleccionada.</p>
  </section>;

  return <section id="contracts-management" className="catalog-panel ui-page" aria-label="Contratación ficticia">
    <PageHeader title="Contratación laboral · prueba local M02"
      description="Borradores y revisiones manuales de demostración, separados por empresa y sede."
      actions={canAdd ? <button type="button" className="button" onClick={() => setPanel('create')}>
        Crear borrador ficticio
      </button> : undefined} />
    <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal, sin trámites, sin datos de trabajadores.</p>
    {notice && <output role="status" aria-live="polite">{notice}</output>}
    <MetricStrip label="Indicadores de contratación de prueba" items={[
      { label: 'Borradores de prueba', value: scopedDrafts.length },
      { label: 'Requieren revisión', value: scopedDrafts.filter(item => item.phase === 'REQUIERE_REVISION').length },
      { label: 'En revisión', value: scopedDrafts.filter(item => item.phase === 'EN_REVISION').length },
    ]} />
    <Card title="Borradores de prueba">
      <Toolbar trailing={<output aria-live="polite">{listing.length} registros</output>}>
        <label>Filtrar por referencia ficticia
          <select value={filter} onChange={event => setFilter(event.target.value)}>
            <option value="">Todas las referencias</option>
            {scopedDrafts.map(item => <option key={item.ref} value={item.ref}>{item.ref}</option>)}
          </select>
        </label>
      </Toolbar>
      <DataTable<Draft> caption="Contratos sintéticos"
        rows={listing} rowKey={item => item.ref}
        onOpen={item => inspect(item)}
        columns={[
          { key: 'ref', header: 'Referencia', render: item => item.ref },
          { key: 'type', header: 'Clase', render: item => item.category },
          { key: 'state', header: 'Estado', render: item =>
            <StatusPill tone={item.phase === 'BORRADOR' ? 'neutral' : 'info'}>{item.phase}</StatusPill> },
          { key: 'action', header: 'Acción', render: item =>
            <button type="button" onClick={() => inspect(item)}>Consultar prueba</button> },
        ]}
        empty={<output>Sin registros en esta empresa y sede.</output>} />
    </Card>
    <Card title="Bitácora local de demostración">
      <p className="muted">No es evidencia ni auditoría de servidor.</p>
      {trail.filter(note => note.scope === scope).length === 0
        ? <p role="status">Sin operaciones de prueba.</p>
        : <ol>{trail.filter(note => note.scope === scope).map(note =>
          <li key={note.id}>{note.ref}: {note.operation}</li>)}</ol>}
    </Card>
    <SidePanel open={panel === 'create' && canAdd} title="Nuevo borrador de prueba"
      onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={createDemo}>
        <label>Contrato sintético
          <input value={ref} required maxLength={64}
            placeholder="fixture:contracts/demo-01"
            pattern="fixture:contracts/(demo|test|sample)-[0-9]{2,3}"
            onChange={event => setRef(event.target.value)} />
        </label>
        <label>Persona sintética
          <input value={fictionalPerson} required maxLength={64}
            placeholder="fixture:person/demo-01"
            pattern="fixture:person/(demo|test|sample)-[0-9]{2,3}"
            onChange={event => setFictionalPerson(event.target.value)} />
        </label>
        <label>Plantilla sintética
          <input value={sourceTemplate} required maxLength={64}
            placeholder="fixture:template/demo-01"
            pattern="fixture:template/(demo|test|sample)-[0-9]{2,3}"
            onChange={event => setSourceTemplate(event.target.value)} />
        </label>
        <label>Tipo de borrador
          <select value={category} onChange={event => setCategory(event.target.value as ContractType)}>
            {allowedType.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
        </label>
        <button type="submit">Crear borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!selected} title="Revisión manual de prueba"
      onClose={() => setPanel(null)}>
      {selected ? <>
        <dl>
          <dt>Referencia</dt><dd>{selected.ref}</dd>
          <dt>Persona ficticia</dt><dd>{selected.fictionalPerson}</dd>
          <dt>Plantilla ficticia</dt><dd>{selected.sourceTemplate}</dd>
          <dt>Estado</dt><dd>
            <StatusPill tone={selected.phase === 'BORRADOR' ? 'neutral' : 'info'}>{selected.phase}</StatusPill>
          </dd>
        </dl>
        {canAdvance && selected.phase !== 'EN_REVISION' && (
          <form onSubmit={advance}><button type="submit">
            {selected.phase === 'BORRADOR' ? 'Solicitar revisión de prueba' : 'Iniciar revisión de prueba'}
          </button></form>
        )}
        <p className="muted">Ningún borrador se aprueba, firma o cierra automáticamente.</p>
      </> : <output>Selecciona una referencia sintética.</output>}
    </SidePanel>
  </section>;
}

// Un montaje por ámbito y solo en DEV. No habilita rutas reales ni persistencia.
export function ContractsManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <ContractPreview key={[props.tenantId, props.companyId, props.branchId].join('|')}
    {...props} />;
}
