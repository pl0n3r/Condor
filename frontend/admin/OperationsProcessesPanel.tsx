import { useEffect, useState } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';
type Task = { id: string; name: string };
type Procedure = { id: string; name: string; tasks: Task[] };
type Process = { id: string; name: string; procedures: Procedure[] };
type State = { kind: 'loading' | 'denied' } | { kind: 'error'; message: string }
  | { kind: 'ready'; processes: Process[] };
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;
export function OperationsProcessesPanel({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('inventory.view');
  const canUpdate = permissions.includes('inventory.update');
  const [state, setState] = useState<State>({ kind: 'loading' });
  const [revision, setRevision] = useState(0);
  const [selected, setSelected] = useState(0);
  const [detailOpen, setDetailOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [taskState, setTaskState] = useState<'pending' | 'done'>('pending');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const url = '/api/v1/branches/' + encodeURIComponent(branchId) + '/operations/processes';
  useEffect(() => {
    if (!canView) {
      setState({ kind: 'denied' });
      return;
    }
    const controller = new AbortController();
    setState({ kind: 'loading' });
    void fetch(url, { credentials: 'same-origin', signal: controller.signal,
      headers: { Accept: 'application/json' } })
      .then(async (response) => {
        if (response.status === 401 || response.status === 403) {
          setState({ kind: 'denied' });
          return;
        }
        if (!response.ok) throw new Error('No se pudo cargar la simulación.');
        const data = await response.json() as { processes?: Process[]; persisted?: boolean };
        if (!Array.isArray(data.processes) || data.persisted !== false) {
          throw new Error('Respuesta de simulación inválida.');
        }
        if (!controller.signal.aborted) setState({ kind: 'ready', processes: data.processes });
      })
      .catch(() => {
        if (!controller.signal.aborted) setState({ kind: 'error',
          message: 'No fue posible consultar procesos sintéticos.' });
      });
    return () => controller.abort();
  }, [url, canView, revision]);
  const choices = state.kind === 'ready'
    ? state.processes.flatMap((process) => process.procedures.flatMap(
      (procedure) => procedure.tasks.map((task) => ({ process, procedure, task })),
    )) : [];
  async function simulate() {
    const choice = choices[selected];
    if (!canUpdate || !choice || busy) return;
    setBusy(true);
    setMessage('');
    try {
      const response = await fetch(url + '/preview', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({
          process_id: choice.process.id,
          procedure_id: choice.procedure.id,
          task_id: choice.task.id,
          state: taskState,
        }),
      });
      if (!response.ok) throw new Error('Simulación rechazada por el servidor.');
      const data = await response.json() as { preview?: { persisted?: boolean; dispatched?: boolean } };
      if (data.preview?.persisted !== false || data.preview.dispatched !== false) {
        throw new Error('Respuesta sin garantía de simulación.');
      }
      setMessage('Estado ficticio validado. No se guardó ni ejecutó ninguna tarea.');
    } catch {
      setMessage('No fue posible simular la tarea.');
    } finally {
      setBusy(false);
    }
  }
  const filtered = choices.filter(({ process, procedure, task }) =>
    (process.name + ' ' + procedure.name + ' ' + task.name)
      .toLocaleLowerCase('es-CO').includes(search.toLocaleLowerCase('es-CO')));
  const current = choices[selected];
  return (
    <section id="operations-processes" className="ui-page" aria-label="Operaciones simuladas">
      <PageHeader title="Procesos y tareas" description="Consulta los procedimientos de la sede y ensaya cambios de estado sin guardar ni despachar tareas."
        actions={canUpdate && choices.length > 0 ? (
          <button type="button" className="button" disabled={busy} onClick={() => setDetailOpen(true)}>Simular tarea</button>
        ) : undefined} />
      {state.kind === 'loading' && <output role="status" aria-live="polite">Cargando procesos ficticios…</output>}
      {state.kind === 'denied' && <p role="alert">Sin permiso para ver procesos.</p>}
      {state.kind === 'error' && <div role="alert" className="alert alert-error">
        {state.message} <button type="button" className="button button-secondary"
          onClick={() => setRevision((value) => value + 1)}>Reintentar</button>
      </div>}
      {state.kind === 'ready' && <>
        <MetricStrip label="Resumen de procesos ficticios" items={[
          { label: 'Procesos', value: state.processes.length },
          { label: 'Procedimientos', value: state.processes.reduce((sum, item) => sum + item.procedures.length, 0) },
          { label: 'Tareas de prueba', value: choices.length },
        ]} />
        <Card title="Tareas de la sede">
          <Toolbar trailing={<span className="ui-count">{filtered.length} de {choices.length}</span>}>
            <label className="ui-search"><span className="ui-sr-only">Buscar tareas</span>
              <input type="search" placeholder="Buscar proceso, procedimiento o tarea"
                value={search} onChange={(event) => setSearch(event.target.value)} />
            </label>
          </Toolbar>
          <DataTable caption="Tareas disponibles para simulación" rows={filtered}
            rowKey={({ process, procedure, task }) => process.id + '/' + procedure.id + '/' + task.id}
            onOpen={(choice) => { setSelected(choices.indexOf(choice)); setDetailOpen(true); }}
            empty={<><strong>No hay tareas para esta búsqueda</strong><span>Prueba otro término o revisa los procesos de la sede.</span></>}
            columns={[
              { key: 'task', header: 'Tarea', render: ({ task }) => task.name },
              { key: 'process', header: 'Proceso', render: ({ process }) => process.name },
              { key: 'procedure', header: 'Procedimiento', render: ({ procedure }) => procedure.name },
              { key: 'status', header: 'Modo', render: () => <StatusPill tone="info">Solo simulación</StatusPill> },
            ]} />
        </Card>
      </>}
      {message && <output className="alert" aria-live="polite">{message}</output>}
      <SidePanel open={state.kind === 'ready' && detailOpen && current !== undefined}
        title="Simular estado de tarea" onClose={() => setDetailOpen(false)}>
        {current && <>
          <dl className="ui-details">
            <div><dt>Proceso</dt><dd>{current.process.name}</dd></div>
            <div><dt>Procedimiento</dt><dd>{current.procedure.name}</dd></div>
            <div><dt>Tarea</dt><dd>{current.task.name}</dd></div>
          </dl>
          <label className="field">Tarea ficticia
            <select value={selected} onChange={(event) => setSelected(Number(event.target.value))}>
              {choices.map(({ process, procedure, task }, index) =>
                <option key={process.id + '/' + procedure.id + '/' + task.id} value={index}>
                  {process.name} / {procedure.name} / {task.name}
                </option>)}
            </select>
          </label>
          <label className="field">Estado de prueba
            <select value={taskState} onChange={(event) =>
              setTaskState(event.target.value === 'done' ? 'done' : 'pending')}>
              <option value="pending">Pendiente</option>
              <option value="done">Completada (simulada)</option>
            </select>
          </label>
          {!canUpdate && <p role="alert">Sin permiso para simular tareas.</p>}
          <p className="muted">La simulación no escribe registros ni ejecuta tareas.</p>
          <button type="button" className="button" disabled={!canUpdate || busy}
            onClick={() => void simulate()}>Simular sin guardar</button>
        </>}
      </SidePanel>
    </section>
  );
}
