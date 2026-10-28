/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';

// M05 es una maqueta: no almacena datos, procesa PILA ni representa trámites reales.
type Stage = 'BORRADOR' | 'REQUIERE_REVISION' | 'EN_REVISION';
type ReferenceType = 'affiliation' | 'employee' | 'plan';
type Entry = { id: string; worker: string; plan: string; stage: Stage; scope: string };
type Event = { key: number; message: string; scope: string };
type Props = Readonly<{ tenantId: string; companyId: string; branchId: string; permissions: string[] }>;

const allowed: Readonly<Record<ReferenceType, RegExp>> = {
  affiliation: /^fixture:affiliation\/[a-z][a-z0-9_-]{0,47}$/,
  employee: /^fixture:employee\/[a-z][a-z0-9_-]{0,47}$/,
  plan: /^fixture:plan\/[a-z][a-z0-9_-]{0,47}$/,
};
const steps: Readonly<Record<Stage, Stage | null>> = {
  BORRADOR: 'REQUIERE_REVISION',
  REQUIERE_REVISION: 'EN_REVISION',
  EN_REVISION: null,
};

function synthetic(type: ReferenceType, input: string): boolean {
  return allowed[type].test(input) && !/[0-9]{7,}/.test(input);
}

function Demo({ tenantId, companyId, branchId, permissions }: Props) {
  const scope = [tenantId, companyId, branchId].join('|');
  const canView = companyId !== '__unassigned__' && permissions.includes('users.view');
  const canCreate = canView && permissions.includes('users.create');
  const canReview = canView && permissions.includes('users.update');
  const [items, setItems] = useState<Entry[]>([]);
  const [history, setHistory] = useState<Event[]>([]);
  const [form, setForm] = useState({ id: '', worker: '', plan: '' });
  const [active, setActive] = useState<string | null>(null);
  const [filter, setFilter] = useState('');
  const [message, setMessage] = useState('');
  const visible = items.filter(item => item.scope === scope);
  const picked = visible.find(item => item.id === active);

  function log(messageText: string) {
    setHistory(previous => [...previous, {
      key: (previous.at(-1)?.key ?? 0) + 1, message: messageText, scope,
    }].slice(-100));
  }

  function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const { id, worker, plan } = form;
    const valid = synthetic('affiliation', id) && synthetic('employee', worker)
      && synthetic('plan', plan);
    if (!canCreate || !valid || visible.length >= 100 || visible.some(item => item.id === id)) {
      setMessage('No se creó la afiliación: permisos, referencias ficticias o duplicados inválidos.');
      return;
    }
    setItems(existing => existing.concat({ id, worker, plan, stage: 'BORRADOR', scope }));
    log('Creación manual de ' + id);
    setActive(id);
    setForm({ id: '', worker: '', plan: '' });
    setMessage('Registro de demostración en memoria. No tiene valor legal.');
  }

  function inspect(id: string) {
    if (!canView || !synthetic('affiliation', id) || !visible.some(item => item.id === id)) return;
    setActive(id);
    log('Consulta manual de ' + id);
  }

  function move(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canReview || !picked || !synthetic('affiliation', picked.id)) return;
    const next = steps[picked.stage];
    if (next === null) return;
    setItems(existing => existing.map(item => item.id === picked.id && item.scope === scope
      ? { ...item, stage: next } : item));
    log('Cambio manual de estado: ' + picked.id + ' → ' + next);
    setMessage('Revisión ficticia registrada. Ningún estado se cierra automáticamente.');
  }

  if (!canView) {
    return <section id="social-security-management" role="alert">
      Demostración no disponible: empresa o permiso de consulta ausente.
    </section>;
  }

  return (
    <section id="social-security-management" className="catalog-panel">
      <h2>Seguridad Social M05 · laboratorio ficticio</h2>
      <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal. No introducir información personal.</p>
      <p className="muted">No se realizan afiliaciones, liquidaciones, pagos ni envíos a PILA.</p>
      {message && <output role="status" aria-live="polite">{message}</output>}
      <div className="catalog-grid">
        {canCreate && <form className="catalog-card" onSubmit={register}>
          <h3>Nueva afiliación de prueba</h3>
          {(['id', 'worker', 'plan'] as const).map(field => {
            const type: ReferenceType = field === 'id' ? 'affiliation' : field === 'worker' ? 'employee' : 'plan';
            return <label key={field}>{field === 'id' ? 'Afiliación' : field === 'worker' ? 'Colaborador' : 'Plan'} ficticio
              <input required maxLength={68} value={form[field]}
                placeholder={'fixture:' + type + '/demo'}
                onChange={event => setForm(previous => ({ ...previous, [field]: event.target.value }))} />
            </label>;
          })}
          <button type="submit">Guardar borrador ficticio</button>
        </form>}
        <section className="catalog-card">
          <h3>Afiliaciones del ámbito activo</h3>
          <label>Filtrar por referencia
            <input aria-label="Filtrar afiliaciones" value={filter} maxLength={68}
              onChange={event => setFilter(event.target.value)} />
          </label>
          <ul>{visible.filter(item => item.id.includes(filter.trim().toLowerCase())).map(item =>
            <li key={item.id}>
              <button type="button" onClick={() => inspect(item.id)}>{item.id} · {item.stage}</button>
            </li>)}</ul>
          {visible.length === 0 && <p role="status">No hay registros ficticios.</p>}
        </section>
        <section className="catalog-card">
          <h3>Revisión manual</h3>
          {!picked ? <p role="status">Selecciona una afiliación ficticia.</p> : <div>
            <dl>
              <dt>Afiliación</dt><dd>{picked.id}</dd>
              <dt>Colaborador</dt><dd>{picked.worker}</dd>
              <dt>Plan</dt><dd>{picked.plan}</dd>
              <dt>Estado</dt><dd>{picked.stage}</dd>
            </dl>
            {canReview && steps[picked.stage] !== null &&
              <form onSubmit={move}>
                <button type="submit">Registrar siguiente revisión ficticia</button>
              </form>}
          </div>}
          <p className="muted">Sin aprobación, pago, envío ni cierre automático.</p>
        </section>
        <section className="catalog-card">
          <h3>Auditoría local de demostración (no oficial)</h3>
          <p>Solo eventos de esta sesión. Las operaciones reales exigen auditoría de servidor.</p>
          <ul>{history.filter(entry => entry.scope === scope).map(entry =>
            <li key={entry.key}>{entry.message}</li>)}</ul>
          {history.length === 0 && <p role="status">Sin eventos ficticios.</p>}
        </section>
      </div>
    </section>
  );
}

export function SocialSecurityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Demo key={[props.tenantId, props.companyId, props.branchId].join('|')} {...props} />;
}
