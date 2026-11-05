import { useEffect, useState } from 'react';
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
  return <section id="operations-processes" className="catalog-panel" aria-label="Operaciones simuladas">
    <h2>Procesos, procedimientos y tareas (solo pruebas)</h2>
    {state.kind === 'denied' && <p role="alert">Sin permiso para ver procesos.</p>}
    {state.kind === 'loading' && <output aria-live="polite">Cargando procesos ficticios…</output>}
    {state.kind === 'error' && <div role="alert">{state.message}
      <button type="button" onClick={() => setRevision((value) => value + 1)}>Reintentar</button>
    </div>}
    {state.kind === 'ready' && choices.length === 0 && <p>No hay tareas ficticias.</p>}
    {state.kind === 'ready' && choices.length > 0 && <>
      <label> Tarea ficticia
        <select value={selected} onChange={(event) => setSelected(Number(event.target.value))}>
          {choices.map(({ process, procedure, task }, index) =>
            <option key={task.id} value={index}>{process.name} / {procedure.name} / {task.name}</option>)}
        </select>
      </label>
      <label>Estado de prueba
        <select value={taskState} onChange={(event) =>
          setTaskState(event.target.value === 'done' ? 'done' : 'pending')}>
          <option value="pending">Pendiente</option><option value="done">Completada (simulada)</option>
        </select>
      </label>
      {!canUpdate && <p role="alert">Sin permiso para simular tareas.</p>}
      <button type="button" disabled={!canUpdate || busy} onClick={() => void simulate()}>
        Simular sin guardar
      </button>
      {message && <output aria-live="polite">{message}</output>}
    </>}
  </section>;
}
