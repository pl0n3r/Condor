/// <reference types="vite/client" />
import { useState, type FormEvent } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

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
  const [panel, setPanel] = useState<'create' | 'detail' | null>(null);
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
    setActive(id); setPanel('detail');
    setForm({ id: '', worker: '', plan: '' });
    setMessage('Registro de demostración en memoria. No tiene valor legal.');
  }

  function inspect(id: string) {
    if (!canView || !synthetic('affiliation', id) || !visible.some(item => item.id === id)) return;
    setActive(id); setPanel('detail');
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

  if (!canView) return <section id="social-security-management" className="catalog-panel ui-page">
    <PageHeader title="Seguridad Social M05 · laboratorio ficticio" />
    <p role="alert">Demostración no disponible: empresa o permiso de consulta ausente.</p>
  </section>;

  const filtered = visible.filter(item => item.id.includes(filter.trim().toLowerCase()));
  const scopedHistory = history.filter(entry => entry.scope === scope);

  return <section id="social-security-management" className="catalog-panel ui-page" aria-label="Seguridad social de demostración">
    <PageHeader title="Seguridad Social M05 · laboratorio ficticio"
      description="Afiliaciones y revisiones de prueba, sin trámites ni información personal."
      actions={canCreate ? <button type="button" className="button" onClick={() => setPanel('create')}>
        Nueva afiliación de prueba
      </button> : undefined} />
    <p role="alert">SOLO DATOS FICTICIOS. Sin valor legal. No introducir información personal.</p>
    <p className="muted">No se realizan afiliaciones, liquidaciones, pagos ni envíos a PILA.</p>
    {message && <output role="status" aria-live="polite">{message}</output>}
    <MetricStrip label="Indicadores de seguridad social ficticia" items={[
      { label: 'Afiliaciones ficticias', value: visible.length },
      { label: 'Borradores', value: visible.filter(item => item.stage === 'BORRADOR').length },
      { label: 'En revisión', value: visible.filter(item => item.stage !== 'BORRADOR').length },
    ]} />
    <Card title="Afiliaciones del ámbito activo">
      <Toolbar trailing={<output aria-live="polite">{filtered.length} resultados</output>}>
        <label>Filtrar por referencia
          <input type="search" aria-label="Filtrar afiliaciones" value={filter} maxLength={68}
            onChange={event => setFilter(event.target.value)} />
        </label>
      </Toolbar>
      <DataTable<Entry> caption="Afiliaciones ficticias de la empresa"
        rows={filtered} rowKey={item => item.id}
        onOpen={item => inspect(item.id)}
        columns={[
          { key: 'id', header: 'Afiliación', render: item => item.id },
          { key: 'worker', header: 'Colaborador', render: item => item.worker },
          { key: 'plan', header: 'Plan', render: item => item.plan },
          { key: 'state', header: 'Estado', render: item =>
            <StatusPill tone={item.stage === 'BORRADOR' ? 'neutral' : 'info'}>{item.stage}</StatusPill> },
        ]}
        empty={<p role="status">{visible.length === 0
          ? 'No hay registros ficticios.' : 'No hay afiliaciones para este filtro.'}</p>} />
    </Card>
    <Card title="Auditoría local de demostración (no oficial)">
      <p>Solo eventos de esta sesión. Las operaciones reales exigen auditoría de servidor.</p>
      {scopedHistory.length === 0
        ? <p role="status">Sin eventos ficticios.</p>
        : <ul>{scopedHistory.map(entry => <li key={entry.key}>{entry.message}</li>)}</ul>}
    </Card>
    <SidePanel open={panel === 'create' && canCreate} title="Nueva afiliación de prueba"
      onClose={() => setPanel(null)}>
      <form className="catalog-editor" onSubmit={register}>
        {(['id', 'worker', 'plan'] as const).map(field => {
          const type: ReferenceType = field === 'id' ? 'affiliation' : field === 'worker' ? 'employee' : 'plan';
          return <label key={field}>{field === 'id' ? 'Afiliación' : field === 'worker' ? 'Colaborador' : 'Plan'} ficticio
            <input required maxLength={68} value={form[field]}
              placeholder={'fixture:' + type + '/demo'}
              onChange={event => setForm(previous => ({ ...previous, [field]: event.target.value }))} />
          </label>;
        })}
        <button type="submit">Guardar borrador ficticio</button>
      </form>
    </SidePanel>
    <SidePanel open={panel === 'detail' && !!picked} title="Revisión manual"
      onClose={() => setPanel(null)}>
      {picked ? <>
        <dl>
          <dt>Afiliación</dt><dd>{picked.id}</dd>
          <dt>Colaborador</dt><dd>{picked.worker}</dd>
          <dt>Plan</dt><dd>{picked.plan}</dd>
          <dt>Estado</dt><dd>
            <StatusPill tone={picked.stage === 'BORRADOR' ? 'neutral' : 'info'}>{picked.stage}</StatusPill>
          </dd>
        </dl>
        {canReview && steps[picked.stage] !== null &&
          <form onSubmit={move}>
            <button type="submit">Registrar siguiente revisión ficticia</button>
          </form>}
      </> : <p role="status">Selecciona una afiliación ficticia.</p>}
      <p className="muted">Sin aprobación, pago, envío ni cierre automático.</p>
    </SidePanel>
  </section>;
}

export function SocialSecurityManagement(props: Props) {
  if (!import.meta.env.DEV) return null;
  return <Demo key={[props.tenantId, props.companyId, props.branchId].join('|')} {...props} />;
}
