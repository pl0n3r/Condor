import { FormEvent, useEffect, useMemo, useState } from 'react';
import {
  platformStaffGrantsPath, platformStaffInvitationPath, platformStaffInvitationResendPath,
  platformStaffInvitationRevokePath, platformStaffPath,
} from './api';
import { DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';

type TenantOption = { id: string; name: string; slug: string };
type PermissionDefinition = { key: string; label: string; permissions: { key: string; action: string; label: string }[] };
type GrantDefinition = { tenant_id: string | null; module: string; actions: string[] };
type StaffRow = {
  id: string; name: string; email: string; active: boolean;
  invitation: { id: string; state: string; expires_at: string };
  grants: GrantDefinition[];
};
type StaffResponse = {
  staff: StaffRow[]; catalog: PermissionDefinition[];
  tenant_options: TenantOption[]; tenant_options_truncated: boolean;
};
type InvitationAction = { staff: StaffRow; action: 'resend' | 'revoke' };
type Props = Readonly<{ csrfToken: string; refreshKey: number }>;

export function PlatformStaffPanel({ csrfToken, refreshKey }: Props) {
  const [data, setData] = useState<StaffResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const [denied, setDenied] = useState(false);
  const [message, setMessage] = useState('');
  const [activationPath, setActivationPath] = useState('');
  const [activationPreviewOpen, setActivationPreviewOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState('all');
  const [editorOpen, setEditorOpen] = useState(false);
  const [confirmAction, setConfirmAction] = useState<InvitationAction | null>(null);
  const [email, setEmail] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [scope, setScope] = useState('*');
  const [moduleKey, setModuleKey] = useState('');
  const [actions, setActions] = useState<string[]>([]);
  const [draftGrants, setDraftGrants] = useState<GrantDefinition[]>([]);
  const [editingStaffId, setEditingStaffId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const tenantNames = useMemo(() => new Map((data?.tenant_options ?? []).map(t => [t.id, t.name])), [data]);
  const filteredStaff = useMemo(() => (data?.staff ?? []).filter(staff => {
    const needle = search.trim().toLocaleLowerCase('es');
    const matches = [staff.name, staff.email, ...staff.grants.map(g => tenantNames.get(g.tenant_id ?? '') ?? g.module)]
      .some(value => value.toLocaleLowerCase('es').includes(needle));
    return matches && (filter === 'all' || (filter === 'active' ? staff.active : !staff.active));
  }), [data, search, filter, tenantNames]);

  async function load() {
    setLoading(true);
    setLoadError('');
    setDenied(false);
    try {
      const response = await fetch(platformStaffPath(), {
        credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      if (response.status === 401 || response.status === 403) {
        setDenied(true);
        setData(null);
        return;
      }
      if (!response.ok) throw new Error('No fue posible cargar el staff de plataforma.');
      const payload = await response.json() as StaffResponse;
      setData(payload);
      setModuleKey(current => current || payload.catalog[0]?.key || '');
    } catch {
      setData(null);
      setLoadError('No fue posible cargar el staff de plataforma.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void load(); }, [refreshKey]);

  function clearEditor() {
    setEditingStaffId(null);
    setEmail('');
    setDisplayName('');
    setDraftGrants([]);
    setActions([]);
    setScope('*');
    setActivationPath('');
    setActivationPreviewOpen(false);
    setMessage('');
  }

  function closeEditor() {
    setEditorOpen(false);
    clearEditor();
  }

  function openCreation() {
    clearEditor();
    setEditorOpen(true);
  }

  function editStaff(staff: StaffRow) {
    clearEditor();
    setEditingStaffId(staff.id);
    setEmail(staff.email);
    setDisplayName(staff.name);
    setDraftGrants(staff.grants.map(g => ({ ...g, actions: [...g.actions] })));
    setEditorOpen(true);
  }

  function toggleAction(action: string) {
    setActions(current => current.includes(action)
      ? current.filter(candidate => candidate !== action)
      : [...current, action].sort((left, right) => left.localeCompare(right)));
  }

  function addGrant() {
    if (!moduleKey || !actions.length) {
      setMessage('Selecciona un módulo y al menos una acción CRUD.');
      return;
    }
    const next: GrantDefinition = { tenant_id: scope === '*' ? null : scope, module: moduleKey, actions: [...actions].sort((left, right) => left.localeCompare(right)) };
    setDraftGrants(current => [...current.filter(g => g.tenant_id !== next.tenant_id || g.module !== next.module), next]);
    setActions([]);
    setMessage('');
  }

  const scopeLabel = (g: GrantDefinition) => g.tenant_id === null
    ? 'Todos los clientes' : tenantNames.get(g.tenant_id) ?? 'Cliente no disponible';
  const moduleLabel = (key: string) => data?.catalog.find(m => m.key === key)?.label ?? key;

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (draftGrants.length === 0) {
      setMessage('Añade al menos un permiso antes de guardar.');
      return;
    }
    setSaving(true);
    setMessage('');
    setActivationPath('');
    try {
      const editing = editingStaffId !== null;
      const response = await fetch(editing ? platformStaffGrantsPath(editingStaffId) : platformStaffInvitationPath(), {
        method: editing ? 'PUT' : 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(editing ? { grants: draftGrants } : { email, display_name: displayName, grants: draftGrants }),
      });
      if (!response.ok) {
        let errorMessage = 'No fue posible guardar el staff de plataforma.';
        if (response.status === 422) errorMessage = 'Revisa el correo, el alcance y los permisos seleccionados.';
        if (response.status === 403) errorMessage = 'No tienes permiso para guardar estos cambios.';
        throw new Error(errorMessage);
      }
      const payload = await response.json() as { invitation?: { activation_path_once?: string } };
      setActivationPath(payload.invitation?.activation_path_once ?? '');
      setMessage(editing ? 'Permisos actualizados.' : 'Staff creado y pendiente de activación.');
      if (editing) {
        setEditorOpen(false);
        clearEditor();
      }
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'No fue posible guardar el staff de plataforma.');
    } finally {
      setSaving(false);
    }
  }

  async function invitationAction() {
    if (!confirmAction) return;
    const { staff, action } = confirmAction;
    setSaving(true);
    setMessage('');
    setActivationPath('');
    try {
      const response = await fetch(action === 'resend'
        ? platformStaffInvitationResendPath(staff.id)
        : platformStaffInvitationRevokePath(staff.id), {
        method: action === 'resend' ? 'POST' : 'DELETE', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
      });
      if (!response.ok) throw new Error(response.status === 403
        ? 'No tienes permiso para cambiar esta invitación.'
        : 'No fue posible actualizar la invitación.');
      if (action === 'resend') {
        const payload = await response.json() as { invitation?: { activation_path_once?: string } };
        setActivationPath(payload.invitation?.activation_path_once ?? '');
        setActivationPreviewOpen(true);
        setMessage('Invitación regenerada. El token anterior quedó inválido.');
      } else {
        setMessage('Invitación revocada.');
      }
      setConfirmAction(null);
      await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'No fue posible actualizar la invitación.');
    } finally {
      setSaving(false);
    }
  }

  let submitLabel = 'Crear staff e invitar';
  if (saving) submitLabel = 'Guardando…';
  else if (editingStaffId) submitLabel = 'Guardar permisos';

  return (
    <section className="platform-section" aria-label="Staff de plataforma">
      <PageHeader title="Staff de plataforma" description="Gestiona el equipo, las invitaciones y los permisos por cliente y módulo."
        actions={<button type="button" className="button" onClick={openCreation} disabled={loading || denied || !!loadError}>Invitar staff</button>} />

      {loading && <output className="access-message" aria-live="polite">Cargando staff…</output>}
      {denied && <p className="access-message" role="alert">No tienes permiso para consultar el staff de plataforma.</p>}
      {loadError && <div className="platform-empty" role="alert">{loadError} <button type="button" className="button button-secondary" onClick={() => void load()}>Reintentar</button></div>}

      {data && !loading && !denied && !loadError && (
        <>
          <MetricStrip label="Resumen de staff" items={[
            { label: 'Personas', value: data.staff.length },
            { label: 'Activos', value: data.staff.filter(s => s.active).length, tone: 'ok' },
            { label: 'Invitaciones pendientes', value: data.staff.filter(s => !s.active).length, tone: 'warn' },
          ]} />
          <Toolbar trailing={<span className="muted">{filteredStaff.length} resultados</span>}>
            <label className="field">Buscar{' '}
              <input type="search" value={search} placeholder="Nombre, correo o cliente" onChange={e => setSearch(e.target.value)} />
            </label>
            <label className="field">Estado{' '}
              <select value={filter} onChange={e => setFilter(e.target.value)}>
                <option value="all">Todos</option><option value="active">Activos</option><option value="pending">Pendientes</option>
              </select>
            </label>
          </Toolbar>
          {filteredStaff.length ? (
            <DataTable<StaffRow> caption="Staff registrado" empty="Aún no hay staff de plataforma." rows={filteredStaff} rowKey={s => s.id} onOpen={editStaff} columns={[
              { key: 'person', header: 'Persona', render: s => <><strong>{s.name}</strong><span className="muted"> · {s.email}</span></> },
              { key: 'state', header: 'Estado', render: s => <StatusPill tone={s.active ? 'ok' : 'warn'}>{s.active ? 'Activo' : s.invitation.state}</StatusPill> },
              { key: 'grants', header: 'Permisos concedidos', render: s => <span>{s.grants.map(g => scopeLabel(g) + ' · ' + moduleLabel(g.module) + ' (' + g.actions.join(', ') + ')').join('; ') || 'Sin permisos'}</span> },
              { key: 'actions', header: 'Acciones', render: s => <div className="role-actions">
                <button type="button" className="button button-secondary" onClick={() => editStaff(s)}>Editar permisos</button>
                {!s.active && <>
                  <button type="button" className="button button-secondary" onClick={() => { setActivationPath(''); setMessage(''); setConfirmAction({ staff: s, action: 'resend' }); }}>Reemitir invitación</button>
                  <button type="button" className="button button-secondary" onClick={() => { setActivationPath(''); setMessage(''); setConfirmAction({ staff: s, action: 'revoke' }); }}>Revocar invitación</button>
                </>}
              </div> },
            ]} />
          ) : <output className="platform-empty">{data.staff.length === 0 ? 'Aún no hay staff de plataforma.' : 'No hay resultados para estos filtros.'}</output>}
        </>
      )}

      <SidePanel open={editorOpen && !!data} title={editingStaffId ? 'Editar permisos' : 'Invitar staff'} onClose={closeEditor}>
        <form className="platform-form" onSubmit={(event) => void submit(event)}>
          <div className="platform-form-grid">
            <label className="field"><span>Nombre</span>
              <input required={!editingStaffId} disabled={!!editingStaffId || saving} value={displayName} onChange={e => setDisplayName(e.target.value)} />
            </label>
            <label className="field"><span>Correo</span>
              <input required={!editingStaffId} disabled={!!editingStaffId || saving} type="email" value={email} onChange={e => setEmail(e.target.value)} />
            </label>
          </div>
          <div className="grant-builder">
            <label className="field"><span>Cliente</span>
              <select aria-label="Cliente" value={scope} onChange={e => setScope(e.target.value)}>
                <option value="*">Todos los clientes</option>
                {data?.tenant_options.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </label>
            <label className="field"><span>Módulo</span>
              <select aria-label="Módulo" value={moduleKey} onChange={e => { setModuleKey(e.target.value); setActions([]); }}>
                {data?.catalog.map(m => <option value={m.key} key={m.key}>{m.label}</option>)}
              </select>
            </label>
            <fieldset className="crud-picker"><legend>CRUD permitido</legend>
              {data?.catalog.find(m => m.key === moduleKey)?.permissions.map(p => (
                <label key={p.action}><input type="checkbox" checked={actions.includes(p.action)} onChange={() => toggleAction(p.action)} />{p.label}</label>
              ))}
            </fieldset>
            <button type="button" className="button button-secondary" onClick={addGrant}>Añadir permiso</button>
          </div>
          {data?.tenant_options_truncated && <p className="muted">Se muestran los primeros 50 clientes.</p>}
          <div className="grant-draft" aria-label="Permisos seleccionados">
            {!draftGrants.length && <span className="muted">Aún no hay permisos seleccionados.</span>}
            {draftGrants.map((g, i) => (
              <div key={(g.tenant_id ?? '*') + g.module} className="grant-row">
                <div><strong>{scopeLabel(g)}</strong><span>{moduleLabel(g.module)} · {g.actions.join(', ')}</span></div>
                <button type="button" className="button button-secondary" onClick={() => setDraftGrants(current => current.filter((_, j) => i !== j))}>Quitar</button>
              </div>
            ))}
          </div>
          {editorOpen && message && <output className="access-message" aria-live="polite">{message}</output>}
          {editorOpen && activationPath && <div className="activation-once" role="note"><strong>Enlace de activación · mostrar una sola vez</strong><code>{activationPath}</code><span>Este enlace no vuelve a aparecer después de cerrar el panel.</span></div>}
          <div className="platform-form-actions">
            <button type="submit" className="button" disabled={saving}>{submitLabel}</button>
            <button type="button" className="button button-secondary" onClick={closeEditor}>Cancelar</button>
          </div>
        </form>
      </SidePanel>

      <SidePanel open={!!confirmAction} title={confirmAction?.action === 'revoke' ? 'Revocar invitación' : 'Reemitir invitación'} onClose={() => { setConfirmAction(null); setMessage(''); }}>
        <p>¿Confirmas que deseas {confirmAction?.action === 'revoke' ? 'revocar' : 'reemitir'} la invitación de {confirmAction?.staff.name}?</p>
        <p className="muted">Esta acción modifica el acceso de la persona y no se puede deshacer desde aquí.</p>
        {confirmAction && message && <output className="access-message" role="alert">{message}</output>}
        <div className="platform-form-actions">
          <button type="button" className="button" disabled={saving} onClick={() => void invitationAction()}>{saving ? 'Procesando…' : 'Confirmar'}</button>
          <button type="button" className="button button-secondary" onClick={() => { setConfirmAction(null); setMessage(''); }}>Cancelar</button>
        </div>
      </SidePanel>

      {!editorOpen && !confirmAction && message && <output className="access-message" aria-live="polite">{message}</output>}
      <SidePanel open={activationPreviewOpen} title="Enlace de activación de un solo uso"
        onClose={() => { setActivationPreviewOpen(false); setActivationPath(''); }}>
        <p>La invitación se ha actualizado. Comparte este enlace mediante un canal seguro.</p>
        {activationPreviewOpen && activationPath && (
          <div className="activation-once" role="note"><strong>Enlace de activación · mostrar una sola vez</strong>
            <code>{activationPath}</code></div>
        )}
        <button type="button" className="button" onClick={() => { setActivationPreviewOpen(false); setActivationPath(''); }}>Cerrar</button>
      </SidePanel>
    </section>
  );
}
