import { useEffect, useState } from 'react';
import { PageHeader, StatusPill } from './ui/Kit';

type ModuleRow = {
  key: string;
  name: string;
  group: string;
  plan_default: boolean;
  tenant_override: boolean | null;
  tenant_enabled: boolean;
};
type UserRow = {
  id: string;
  name: string;
  email: string;
  enabled: Record<string, boolean>;
  overrides: Record<string, boolean>;
};
type ModuleSnapshot = {
  tenant: { id: string; name: string };
  modules: ModuleRow[];
  users: UserRow[];
  csrf_token: string;
};
type LoadState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: ModuleSnapshot };

export function PlatformTenantModulesPanel({ tenantId }: Readonly<{ tenantId: string }>) {
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [tenantEdits, setTenantEdits] = useState<Record<string, boolean>>({});
  const [userEdits, setUserEdits] = useState<Record<string, boolean>>({});
  const [search, setSearch] = useState('');
  const [saving, setSaving] = useState<'tenant' | 'users' | null>(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const endpoint = '/adminpl0n3r/api/tenants/' + encodeURIComponent(tenantId) + '/modules';

  useEffect(() => {
    let active = true;
    setState({ status: 'loading' });
    setTenantEdits({});
    setUserEdits({});
    setMessage('');
    setError('');
    void fetch(endpoint, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(async (response) => {
      if (!response.ok) throw new Error('No se pudieron consultar los módulos.');
      const data = await response.json() as ModuleSnapshot;
      if (active) setState({ status: 'ready', data });
    }).catch(() => {
      if (active) setState({ status: 'error' });
    });
    return () => { active = false; };
  }, [endpoint]);

  async function save(section: 'tenant' | 'users') {
    if (state.status !== 'ready' || saving !== null) return;
    const changes = section === 'tenant'
      ? Object.entries(tenantEdits).map(([key, enabled]) => ({ key, enabled }))
      : Object.entries(userEdits).map(([idAndKey, enabled]) => {
        const index = idAndKey.indexOf(':');
        return { user_id: idAndKey.slice(0, index), key: idAndKey.slice(index + 1), enabled };
      });
    if (changes.length === 0) return;
    setSaving(section);
    setError('');
    setMessage('');
    try {
      const response = await fetch(endpoint + (section === 'users' ? '/users' : ''), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.data.csrf_token,
        },
        body: JSON.stringify({ changes }),
      });
      if (!response.ok) throw new Error('No se pudieron guardar los cambios. Revisa tus permisos e inténtalo de nuevo.');
      const refreshed = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!refreshed.ok) throw new Error('Guardado confirmado, pero no fue posible verificar el estado. Actualiza la página.');
      const data = await refreshed.json() as ModuleSnapshot;
      setState({ status: 'ready', data });
      if (section === 'tenant') setTenantEdits({});
      else setUserEdits({});
      setMessage(section === 'tenant'
        ? 'Módulos de la empresa guardados y verificados.'
        : 'Accesos por usuario guardados y verificados.');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'No se pudo completar el guardado.');
    } finally {
      setSaving(null);
    }
  }

  if (state.status === 'loading') {
    return <section className="ui-page module-control" aria-label="Módulos de la empresa">
      <h2>Módulos</h2><output aria-live="polite">Cargando módulos y accesos…</output>
    </section>;
  }
  if (state.status === 'error') {
    return <section className="ui-page module-control" aria-label="Módulos de la empresa">
      <h2>Módulos</h2><p role="alert">No fue posible cargar los accesos. Actualiza la página e inténtalo de nuevo.</p>
    </section>;
  }

  const { modules, users } = state.data;
  const groups = [...new Set(modules.map((module) => module.group))];
  const tenantEnabled = (module: ModuleRow) => tenantEdits[module.key] ?? module.tenant_enabled;
  const userKey = (user: UserRow, module: ModuleRow) => user.id + ':' + module.key;
  const userEnabled = (user: UserRow, module: ModuleRow) =>
    userEdits[userKey(user, module)] ?? user.overrides[module.key] ?? true;
  const filtered = users.filter((user) => (user.name + ' ' + user.email)
    .toLocaleLowerCase('es-CO').includes(search.trim().toLocaleLowerCase('es-CO')));

  return <section className="ui-page module-control admin-form-layout-page" aria-label="Módulos de la empresa">
    <PageHeader title="Módulos" description={'Gestiona los módulos de ' + state.data.tenant.name
      + '. Los permisos y la sede siguen aplicándose; desactivar un módulo bloquea también su API.'} />
    <section className="module-control-section" aria-label="Módulos activos de la empresa">
      <header className="module-control-heading"><h3>Por empresa</h3>
        <p className="muted">Por defecto rige el plan vigente. Los cambios del propietario prevalecen.</p>
      </header>
      <div className="module-control-groups">
        {groups.map((group) => <fieldset className="module-control-group" key={group}>
          <legend>{group}</legend>
          {modules.filter((module) => module.group === group).map((module) =>
            <label key={module.key} className="module-control-item">
              <span className="module-control-copy"><strong>{module.name}</strong>
                <span className="muted">{module.tenant_override === null ? 'Heredado del plan' : 'Definido por propietario'}</span>
              </span>
              <span className="module-control-state"><StatusPill tone={tenantEnabled(module) ? 'ok' : 'neutral'}>
                {tenantEnabled(module) ? 'Activo' : 'Inactivo'}
              </StatusPill>
              <input type="checkbox" role="switch" aria-label={'Activar ' + module.name + ' para la empresa'}
                checked={tenantEnabled(module)} disabled={saving !== null}
                onChange={(event) => setTenantEdits((current) =>
                  ({ ...current, [module.key]: event.target.checked }))} /></span>
            </label>)}
        </fieldset>)}
      </div>
      <div className="module-control-actions">
        <button className="button" type="button" disabled={saving !== null || Object.keys(tenantEdits).length === 0}
          onClick={() => void save('tenant')}>
          {saving === 'tenant' ? 'Guardando…' : 'Guardar cambios de empresa'}
        </button>
      </div>
    </section>
    <section className="module-control-section" aria-label="Accesos por usuario">
      <header className="module-control-heading"><h3>Por usuario</h3>
        <p className="muted">Cada usuario solo puede utilizar módulos activos para la empresa. Se mantienen los permisos de su rol.</p>
      </header>
      <label className="field module-control-search"><span>Buscar usuario</span>
        <input type="search" value={search} onChange={(event) => setSearch(event.target.value)}
          placeholder="Nombre o correo" /></label>
      {filtered.length === 0 ? <p role="status" className="muted">No hay usuarios activos que coincidan con la búsqueda.</p>
        : <div className="ui-table-wrap module-control-users">
          <table className="ui-table"><caption className="ui-sr-only">Permisos de módulos por usuario</caption>
            <thead><tr><th scope="col">Usuario</th><th scope="col">Módulos permitidos</th></tr></thead>
            <tbody>{filtered.map((user) => <tr key={user.id}>
              <th scope="row"><strong>{user.name}</strong><span className="module-control-email">{user.email}</span></th>
              <td><div className="module-control-user-options">
                {modules.map((module) => <label key={module.key} className="module-control-user-option">
                  <input type="checkbox" aria-label={module.name + ' para ' + user.name}
                    checked={tenantEnabled(module) && userEnabled(user, module)}
                    disabled={saving !== null || !tenantEnabled(module)}
                    onChange={(event) => setUserEdits((current) =>
                      ({ ...current, [userKey(user, module)]: event.target.checked }))} />
                  <span>{module.name}</span>
                </label>)}
              </div></td>
            </tr>)}</tbody>
          </table>
        </div>}
      <div className="module-control-actions">
        <button className="button" type="button" disabled={saving !== null || Object.keys(userEdits).length === 0}
          onClick={() => void save('users')}>
          {saving === 'users' ? 'Guardando…' : 'Guardar cambios de usuarios'}
        </button>
      </div>
    </section>
    <output className="module-control-notice" role={error ? 'alert' : 'status'} aria-live="polite">
      {error || message}
    </output>
  </section>;
}
