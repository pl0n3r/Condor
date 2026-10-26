import { FormEvent, useEffect, useRef, useState } from 'react';

type Workflow = {
  workflow_id: string;
  trigger: string;
  conditions: Array<{ field: string; operator: string; value?: unknown }>;
  actions: Array<{ type: string }>;
  active: boolean;
  version: number;
};
type Execution = {
  id: string;
  definition_version: number;
  result: string;
  reason: string;
  created_at: string;
};
type Snapshot = { workflows: Workflow[]; actions: string[] };
type Load = { kind: 'loading' } | { kind: 'ready'; data: Snapshot }
  | { kind: 'error'; message: string };
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;

const BRANCH_ID = /^[0-9A-HJKMNP-TV-Z]{26}$/;
const WORKFLOW_ID = /^[a-zA-Z0-9_.:-]{1,128}$/;

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function readWorkflow(value: unknown): value is Workflow {
  return record(value) && typeof value.workflow_id === 'string'
    && WORKFLOW_ID.test(value.workflow_id)
    && typeof value.trigger === 'string' && value.trigger.length > 0
    && value.trigger.length <= 128
    && typeof value.active === 'boolean'
    && Number.isSafeInteger(value.version) && (value.version as number) > 0
    && Array.isArray(value.conditions) && Array.isArray(value.actions)
    && value.conditions.length <= 20 && value.actions.length <= 10
    && value.actions.every((item: unknown) =>
      record(item) && typeof item.type === 'string');
}

function readWorkflows(value: unknown): Workflow[] {
  if (!record(value) || !Array.isArray(value.workflows)
    || !value.workflows.every(readWorkflow)) {
    throw new Error('La lista de workflows recibida no es válida.');
  }
  return value.workflows;
}

function readCatalog(value: unknown): string[] {
  if (!record(value) || !Array.isArray(value.actions)
    || !value.actions.every((item: unknown) => (
      item === 'inventory.restock' || item === 'notifications.queue'
    ))) {
    throw new Error('El catálogo de acciones es inválido.');
  }
  return value.actions;
}

function readHistory(value: unknown): Execution[] {
  if (!record(value) || !Array.isArray(value.executions)
    || !value.executions.every((item: unknown) => record(item)
      && typeof item.id === 'string'
      && Number.isSafeInteger(item.definition_version)
      && typeof item.result === 'string'
      && typeof item.reason === 'string'
      && typeof item.created_at === 'string')) {
    throw new Error('El historial recibido no es válido.');
  }
  return value.executions;
}

function workflowPath(branchId: string, workflowId?: string): string {
  if (!BRANCH_ID.test(branchId)) {
    throw new Error('El identificador de sede no es válido.');
  }
  const base = '/api/v1/branches/' + branchId + '/workflows';
  if (workflowId === undefined) return base;
  if (!WORKFLOW_ID.test(workflowId)) {
    throw new Error('El identificador de workflow no es válido.');
  }
  return base + '/' + encodeURIComponent(workflowId);
}

function requestError(status: number): Error {
  if (status === 401 || status === 403) {
    return new Error('No tienes permiso para administrar workflows en esta sede.');
  }
  if (status === 409) {
    return new Error('Otro cambio actualizó el workflow. Recarga antes de continuar.');
  }
  if (status === 404) return new Error('El workflow ya no está disponible.');
  if (status === 422 || status === 400) {
    return new Error('La configuración no es válida. Revisa los campos.');
  }
  return new Error('No fue posible comunicar los workflows con el servidor.');
}

async function request(path: string, method = 'GET', csrfToken?: string,
  payload?: object): Promise<unknown> {
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(method !== 'GET' ? {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken ?? '',
      } : {}),
    },
    ...(payload === undefined ? {} : { body: JSON.stringify(payload) }),
  });
  if (!response.ok) throw requestError(response.status);
  try {
    return await response.json() as unknown;
  } catch {
    throw new Error('El servidor devolvió una respuesta inválida.');
  }
}

export function WorkflowsPanel({ branchId, permissions, csrfToken }: Props) {
  const allowed = permissions.includes('settings.update');
  const [state, setState] = useState<Load>({ kind: 'loading' });
  const [workflowId, setWorkflowId] = useState('');
  const [trigger, setTrigger] = useState('');
  const [actionType, setActionType] = useState('');
  const [selected, setSelected] = useState('');
  const [history, setHistory] = useState<Execution[] | null>(null);
  const [trace, setTrace] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const latest = useRef(0);
  const detailRequest = useRef(0);

  async function load() {
    if (!allowed) return;
    const epoch = ++latest.current;
    setState({ kind: 'loading' });
    try {
      const base = workflowPath(branchId);
      const [list, actions] = await Promise.all([
        request(base), request(base + '/action-catalog'),
      ]);
      if (epoch !== latest.current) return;
      const snapshot = { workflows: readWorkflows(list), actions: readCatalog(actions) };
      setState({ kind: 'ready', data: snapshot });
      setActionType((current) => snapshot.actions.includes(current)
        ? current : snapshot.actions[0] ?? '');
    } catch (error) {
      if (epoch !== latest.current) return;
      setState({ kind: 'error', message: error instanceof Error
        ? error.message : 'No fue posible cargar los workflows.' });
    }
  }

  useEffect(() => {
    setSelected('');
    setHistory(null);
    setTrace(null);
    setNotice(null);
    setWorkflowId('');
    setTrigger('');
    if (allowed) void load();
    return () => {
      latest.current += 1;
      detailRequest.current += 1;
    };
  }, [branchId, allowed]);

  const ready = state.kind === 'ready' ? state.data : null;
  const current = ready?.workflows.find((item) => item.workflow_id === selected);
  const validNewId = WORKFLOW_ID.test(workflowId.trim());
  const validTrigger = trigger.trim().length > 0 && trigger.trim().length <= 128;

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!allowed || busy || !validNewId || !validTrigger
      || !ready?.actions.includes(actionType)) {
      setNotice('Ingresa un identificador, disparador y acción válidos.');
      return;
    }
    const epoch = latest.current;
    setBusy(true);
    setNotice(null);
    try {
      const result = await request(workflowPath(branchId), 'POST', csrfToken, {
        workflow_id: workflowId.trim(),
        trigger: trigger.trim(),
        conditions: [],
        actions: [{ type: actionType }],
        active: false,
      });
      if (!record(result) || !readWorkflow(result.workflow)) {
        throw new Error('El servidor no confirmó la creación del workflow.');
      }
      if (epoch !== latest.current) return;
      setSelected(result.workflow.workflow_id);
      setWorkflowId('');
      setTrigger('');
      setHistory(null);
      setTrace(null);
      setNotice('Workflow creado desactivado. Actívalo cuando esté listo.');
      await load();
    } catch (error) {
      if (epoch === latest.current) setNotice(error instanceof Error
        ? error.message : 'No fue posible crear el workflow.');
    } finally {
      setBusy(false);
    }
  }

  async function toggle(item: Workflow) {
    if (!allowed || busy) return;
    const epoch = latest.current;
    setBusy(true);
    setNotice(null);
    try {
      const updated = await request(workflowPath(branchId, item.workflow_id),
        'PUT', csrfToken, {
          expected_version: item.version,
          trigger: item.trigger,
          conditions: item.conditions,
          actions: item.actions,
          active: !item.active,
        });
      if (!record(updated) || !readWorkflow(updated.workflow)
        || updated.workflow.active !== !item.active) {
        throw new Error('El servidor no confirmó el cambio de estado.');
      }
      if (epoch !== latest.current) return;
      setNotice(item.active ? 'Workflow desactivado.' : 'Workflow activado.');
      await load();
    } catch (error) {
      if (epoch === latest.current) {
        setNotice(error instanceof Error ? error.message
          : 'No fue posible modificar el workflow.');
        await load(); // También refresca después de un 409; nunca sobrescribe la versión.
      }
    } finally {
      setBusy(false);
    }
  }

  async function showHistory(item: Workflow) {
    const epoch = ++detailRequest.current;
    setSelected(item.workflow_id);
    setHistory(null);
    setTrace(null);
    setDetailLoading(true);
    setNotice(null);
    try {
      const response = await request(
        workflowPath(branchId, item.workflow_id) + '/history?limit=50');
      if (epoch !== detailRequest.current) return;
      setHistory(readHistory(response));
    } catch (error) {
      if (epoch === detailRequest.current) setNotice(error instanceof Error
        ? error.message : 'No fue posible cargar el historial.');
    } finally {
      if (epoch === detailRequest.current) setDetailLoading(false);
    }
  }

  async function simulate(item: Workflow) {
    if (!allowed || busy) return;
    const epoch = ++detailRequest.current;
    setSelected(item.workflow_id);
    setTrace(null);
    setBusy(true);
    setNotice(null);
    try {
      // Solo un evento sintético: no se envían registros de clientes ni efectos reales.
      const result = await request(
        workflowPath(branchId, item.workflow_id) + '/dry-run',
        'POST', csrfToken, { event_type: item.trigger, event: {} },
      );
      if (!record(result) || result.persisted !== false || !record(result.trace)) {
        throw new Error('La simulación no confirmó que fuera sin persistencia.');
      }
      if (epoch !== detailRequest.current) return;
      setTrace(JSON.stringify(result.trace).slice(0, 4000));
      setNotice('Simulación terminada. No se ejecutaron acciones ni se registró una ejecución.');
    } catch (error) {
      if (epoch === detailRequest.current) setNotice(error instanceof Error
        ? error.message : 'No fue posible simular el workflow.');
    } finally {
      if (epoch === detailRequest.current) setBusy(false);
    }
  }

  if (!allowed) return null;
  return (
    <section id="workflows" aria-labelledby="workflows-title">
      <h2 id="workflows-title">Automatizaciones · Workflows</h2>
      <p className="muted">Configura acciones internas por sede. La simulación no produce efectos.</p>
      <div aria-live="polite">
        {state.kind === 'loading' && <p role="status">Cargando workflows…</p>}
        {state.kind === 'error' && <p role="alert">{state.message}</p>}
        {notice !== null && <p role="status">{notice}</p>}
      </div>
      {state.kind === 'error' && (
        <button type="button" onClick={() => { void load(); }}>Reintentar</button>
      )}
      {ready !== null && (
        <>
          <h3>Crear workflow</h3>
          <form onSubmit={(event) => { void create(event); }}>
            <label htmlFor="workflow-id">Identificador</label>
            <input id="workflow-id" value={workflowId} maxLength={128} required
              onChange={(event) => setWorkflowId(event.target.value)}
              aria-invalid={workflowId.length > 0 && !validNewId} />
            <label htmlFor="workflow-trigger">Disparador</label>
            <input id="workflow-trigger" value={trigger} maxLength={128} required
              onChange={(event) => setTrigger(event.target.value)} />
            <label htmlFor="workflow-action">Acción interna autorizada</label>
            <select id="workflow-action" value={actionType}
              onChange={(event) => setActionType(event.target.value)}>
              {ready.actions.map((action) => (
                <option value={action} key={action}>{action}</option>
              ))}
            </select>
            <p className="muted">Se crea desactivado. No se ejecutan acciones al guardar.</p>
            <button type="submit" disabled={busy || !validNewId || !validTrigger
              || !ready.actions.includes(actionType)}>Crear desactivado</button>
          </form>
          <h3>Workflows configurados</h3>
          {ready.workflows.length === 0 ? (
            <p>No hay workflows configurados para esta empresa.</p>
          ) : (
            <div className="table-responsive">
              <table>
                <caption>Automatizaciones disponibles en el tenant activo</caption>
                <thead><tr>
                  <th scope="col">Identificador</th>
                  <th scope="col">Disparador</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Versión</th>
                  <th scope="col">Acciones</th>
                </tr></thead>
                <tbody>{ready.workflows.map((item) => (
                  <tr key={item.workflow_id}>
                    <th scope="row">{item.workflow_id}</th>
                    <td>{item.trigger}</td>
                    <td>{item.active ? 'Activo' : 'Desactivado'}</td>
                    <td>{item.version}</td>
                    <td>
                      <button type="button" disabled={busy}
                        onClick={() => { void toggle(item); }}>
                        {item.active ? 'Desactivar' : 'Activar'}
                      </button>{' '}
                      <button type="button" disabled={busy}
                        onClick={() => { void simulate(item); }}>Simular sin efectos</button>{' '}
                      <button type="button" disabled={busy || detailLoading}
                        onClick={() => { void showHistory(item); }}>Ver historial</button>
                    </td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
          {selected !== '' && (
            <div aria-live="polite">
              <h3>Detalle de {current?.workflow_id ?? selected}</h3>
              {detailLoading && <p role="status">Cargando historial…</p>}
              {history !== null && (history.length === 0 ? (
                <p>No hay ejecuciones registradas para este workflow.</p>
              ) : (
                <ul>{history.map((item) => (
                  <li key={item.id}>{item.created_at} · {item.result}
                    {' · '}{item.reason} · versión {item.definition_version}</li>
                ))}</ul>
              ))}
              {trace !== null && (
                <>
                  <p>Vista previa sintética, persisted: false.</p>
                  <pre>{trace}</pre>
                </>
              )}
            </div>
          )}
        </>
      )}
    </section>
  );
}
