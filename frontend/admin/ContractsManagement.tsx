/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

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
    setPicked(ref);
    setRef('');
    setFictionalPerson('');
    setSourceTemplate('');
    setNotice('Borrador ficticio creado en esta pantalla. Sin valor legal.');
  }

  function inspect(item: Draft) {
    if (!canRead || item.scope !== scope) return;
    setPicked(item.ref);
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

  if (!canRead) return (
    <section aria-label="Contratación ficticia"><p role="alert">
      No tienes permiso de lectura de la demostración o no hay empresa seleccionada.
    </p></section>
  );

  return (
    <section id="contracts-management" className="catalog-panel" aria-labelledby="m02-title">
      <header>
        <h2 id="m02-title">Contratación laboral · prueba local M02</h2>
        <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal, sin trámites, sin datos de trabajadores.</p>
      </header>
      {notice && <output aria-live="polite">{notice}</output>}
      <div className="catalog-grid">
        <section className="catalog-card" aria-label="Borradores de prueba">
          <label><span>Filtrar por referencia ficticia</span>
            <select value={filter} onChange={event => setFilter(event.target.value)}>
              <option value="">Todas las referencias</option>
              {scopedDrafts.map(item => <option key={item.ref} value={item.ref}>{item.ref}</option>)}
            </select>
          </label>
          {listing.length === 0 ? <output>Sin registros en esta empresa y sede.</output> : (
            <table aria-label="Contratos sintéticos"><thead><tr>
              <th>Referencia</th><th>Clase</th><th>Estado</th><th>Acción</th>
            </tr></thead><tbody>
              {listing.map(item => <tr key={item.ref}>
                <td>{item.ref}</td><td>{item.category}</td><td>{item.phase}</td>
                <td><button type="button" onClick={() => inspect(item)}>Consultar prueba</button></td>
              </tr>)}
            </tbody></table>
          )}
        </section>
        {canAdd && <section className="catalog-card" aria-label="Nuevo borrador de prueba">
          <form className="catalog-editor" onSubmit={createDemo}>
            <label><span>Contrato sintético</span>
              <input value={ref} required maxLength={64}
                placeholder="fixture:contracts/demo-01"
                pattern="fixture:contracts/(demo|test|sample)-[0-9]{2,3}"
                onChange={event => setRef(event.target.value)} />
            </label>
            <label><span>Persona sintética</span>
              <input value={fictionalPerson} required maxLength={64}
                placeholder="fixture:person/demo-01"
                pattern="fixture:person/(demo|test|sample)-[0-9]{2,3}"
                onChange={event => setFictionalPerson(event.target.value)} />
            </label>
            <label><span>Plantilla sintética</span>
              <input value={sourceTemplate} required maxLength={64}
                placeholder="fixture:template/demo-01"
                pattern="fixture:template/(demo|test|sample)-[0-9]{2,3}"
                onChange={event => setSourceTemplate(event.target.value)} />
            </label>
            <label><span>Tipo de borrador</span>
              <select value={category} onChange={event => setCategory(event.target.value as ContractType)}>
                {allowedType.map(item => <option key={item} value={item}>{item}</option>)}
              </select>
            </label>
            <button type="submit">Crear borrador ficticio</button>
          </form>
        </section>}
        <section className="catalog-card" aria-label="Ficha del borrador">
          <h3>Revisión manual de prueba</h3>
          {!selected ? <output>Selecciona una referencia sintética.</output> : <>
            <dl><dt>Referencia</dt><dd>{selected.ref}</dd>
              <dt>Persona ficticia</dt><dd>{selected.fictionalPerson}</dd>
              <dt>Plantilla ficticia</dt><dd>{selected.sourceTemplate}</dd>
              <dt>Estado</dt><dd>{selected.phase}</dd></dl>
            {canAdvance && selected.phase !== 'EN_REVISION' && (
              <form onSubmit={advance}><button type="submit">
                {selected.phase === 'BORRADOR' ? 'Solicitar revisión de prueba' : 'Iniciar revisión de prueba'}
              </button></form>
            )}
            <p className="muted">Ningún borrador se aprueba, firma o cierra automáticamente.</p>
          </>}
        </section>
        <aside className="catalog-card" aria-label="Operaciones del simulador">
          <h3>Bitácora local de demostración</h3>
          <p className="muted">No es evidencia ni auditoría de servidor.</p>
          <ol>{trail.filter(note => note.scope === scope).map(note =>
            <li key={note.id}>{note.ref}: {note.operation}</li>)}</ol>
        </aside>
      </div>
    </section>
  );
}

// Un montaje por ámbito y solo en DEV. No habilita rutas reales ni persistencia.
export function ContractsManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <ContractPreview key={[props.tenantId, props.companyId, props.branchId].join('|')}
    {...props} />;
}
