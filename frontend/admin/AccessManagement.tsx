import { GestM14ReviewIncident } from './GestM14ReviewIncident';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { Card, DataTable, MetricStrip, PageHeader, SidePanel, StatusPill, Toolbar } from './ui/Kit';
import {
  membershipRolePath,
  membershipsPath,
  rolePath,
  rolesPath,
} from './api';

type PermissionDefinition = {
  key: string;
  action: string;
  label: string;
};

type PermissionModule = {
  key: string;
  label: string;
  permissions: PermissionDefinition[];
};

type Role = {
  id: string;
  name: string;
  permissions: string[];
};

type Membership = {
  id: string;
  user: {
    id: string;
    name: string;
    email: string;
  };
  tenant_owner: boolean;
  role_ids: string[];
};

type Props = Readonly<{
  branchId: string;
  permissions: string[];
  csrfToken: string;
}>;

type RoleForm = {
  id: string | null;
  name: string;
  permissions: string[];
};

const emptyForm: RoleForm = { id: null, name: '', permissions: [] };

export function AccessManagement({
  branchId,
  permissions,
  csrfToken,
}: Props) {
  const [roles, setRoles] = useState<Role[]>([]);
  const [catalog, setCatalog] = useState<PermissionModule[]>([]);
  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [form, setForm] = useState<RoleForm>(emptyForm);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [rolePanelOpen, setRolePanelOpen] = useState(false);
  const [selectedMemberId, setSelectedMemberId] = useState<string | null>(null);
  const [roleSearch, setRoleSearch] = useState('');
  const [userSearch, setUserSearch] = useState('');

  const can = (permission: string) => permissions.includes(permission);
  const canSeeRoles = can('roles.view');
  const canSeeUsers = can('users.view');

  async function load() {
    const requests: Promise<void>[] = [];

    if (canSeeRoles) {
      requests.push(
        fetch(rolesPath(branchId), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }).then(async (response) => {
          if (!response.ok) {
            throw new Error('No fue posible cargar los roles');
          }

          const data = await response.json() as {
            roles: Role[];
            catalog: PermissionModule[];
          };
          setRoles(data.roles);
          setCatalog(data.catalog);
        }),
      );
    } else {
      setRoles([]);
      setCatalog([]);
    }

    if (canSeeUsers) {
      requests.push(
        fetch(membershipsPath(branchId), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        }).then(async (response) => {
          if (!response.ok) {
            throw new Error('No fue posible cargar los usuarios');
          }

          const data = await response.json() as {
            memberships: Membership[];
          };
          setMemberships(data.memberships);
        }),
      );
    } else {
      setMemberships([]);
    }

    try {
      await Promise.all(requests);
    } catch {
      setMessage(
        'No pudimos cargar roles y accesos. Intenta de nuevo.',
      );
    }
  }

  useEffect(() => {
    setForm(emptyForm);
    setMessage('');
    void load();
  }, [branchId, permissions.join('|')]);

  const selected = useMemo(
    () => new Set(form.permissions),
    [form.permissions],
  );

  function togglePermission(key: string) {
    setForm((current) => ({
      ...current,
      permissions: current.permissions.includes(key)
        ? current.permissions.filter((item) => item !== key)
        : [...current.permissions, key].sort(
            (left, right) => left.localeCompare(right),
          ),
    }));
  }

  async function submitRole(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) {
      return;
    }

    setBusy(true);
    setMessage('');

    try {
      const endpoint = form.id
        ? rolePath(branchId, form.id)
        : rolesPath(branchId);
      const response = await fetch(endpoint, {
        method: form.id ? 'PATCH' : 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
          name: form.name,
          permissions: form.permissions,
        }),
      });
      if (!response.ok) {
        throw new Error('No fue posible guardar el rol');
      }

      const wasEditing = form.id !== null;
      setForm(emptyForm);
      setRolePanelOpen(false);
      setMessage(wasEditing ? 'Rol actualizado.' : 'Rol creado.');
      await load();
    } catch {
      setMessage(
        'No pudimos guardar el rol. Revisa el nombre y los permisos.',
      );
    } finally {
      setBusy(false);
    }
  }

  async function deactivate(role: Role) {
    if (!can('roles.delete') || busy) {
      return;
    }

    setBusy(true);
    setMessage('');

    try {
      const response = await fetch(
        rolePath(branchId, role.id),
        {
          method: 'DELETE',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken },
        },
      );
      if (!response.ok) {
        throw new Error('No fue posible desactivar el rol');
      }

      if (form.id === role.id) {
        setForm(emptyForm);
      }
      setMessage('Rol desactivado.');
      await load();
    } catch {
      setMessage('No pudimos desactivar el rol.');
    } finally {
      setBusy(false);
    }
  }

  async function toggleAssignment(
    member: Membership,
    role: Role,
    assign: boolean,
  ) {
    if (!can('users.update') || member.tenant_owner || busy) {
      return;
    }

    setBusy(true);
    setMessage('');

    try {
      const response = await fetch(
        membershipRolePath(branchId, member.id, role.id),
        {
          method: assign ? 'PUT' : 'DELETE',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrfToken },
        },
      );
      if (!response.ok) {
        throw new Error('No fue posible actualizar el acceso');
      }

      setMessage('Acceso actualizado.');
      await load();
    } catch {
      setMessage('No pudimos actualizar los roles del usuario.');
    } finally {
      setBusy(false);
    }
  }

  const visibleRoles = roles.filter((role) => role.name.toLocaleLowerCase('es-CO')
    .includes(roleSearch.toLocaleLowerCase('es-CO')));
  const visibleMembers = memberships.filter((member) =>
    (member.user.name + ' ' + member.user.email)
      .toLocaleLowerCase('es-CO').includes(userSearch.toLocaleLowerCase('es-CO')));
  const selectedMember = memberships.find((member) => member.id === selectedMemberId);
  if (!canSeeRoles && !canSeeUsers) {
    return <section id="roles" className="ui-page" aria-label="Roles y permisos">
      <PageHeader title="Roles y permisos" description="Acceso por sede y asignación de responsabilidades." />
      <p className="alert alert-error" role="alert">No tienes permisos para consultar la administración de accesos en esta sede.</p>
    </section>;
  }

  return (
    <section id="roles" className="ui-page" aria-label="Roles y permisos">
      <PageHeader title="Roles y permisos"
        description="Los permisos se acumulan cuando una persona tiene varios roles."
        actions={can('roles.create') ? <button type="button" className="button" disabled={busy}
          onClick={() => { setForm(emptyForm); setRolePanelOpen(true); }}>Nuevo rol</button> : undefined} />
      {message && <output className="access-message" aria-live="polite">{message}</output>}
      <MetricStrip label="Resumen de acceso" items={[
        { label: 'Roles disponibles', value: canSeeRoles ? roles.length : 'Sin acceso' },
        { label: 'Usuarios', value: canSeeUsers ? memberships.length : 'Sin acceso' },
        { label: 'Roles con permisos', value: canSeeRoles
          ? roles.filter((role) => role.permissions.length > 0).length : 'Sin acceso' },
      ]} />
      {canSeeRoles && <>
        <Card title="Roles activos">
          <Toolbar trailing={<span className="ui-count">{visibleRoles.length} de {roles.length}</span>}>
            <label className="ui-search"><span className="ui-sr-only">Buscar rol</span>
              <input type="search" placeholder="Buscar por nombre del rol" value={roleSearch}
                onChange={(event) => setRoleSearch(event.target.value)} />
            </label>
          </Toolbar>
          <DataTable caption="Roles y permisos de la sede" rows={visibleRoles}
            rowKey={(role) => role.id}
            onOpen={can('roles.update') ? (role) => {
              setForm({ id: role.id, name: role.name, permissions: role.permissions });
              setRolePanelOpen(true);
            } : undefined}
            empty={<><strong>Sin roles para mostrar</strong><span>Prueba otro nombre o crea un rol cuando tengas permiso.</span></>}
            columns={[
              { key: 'name', header: 'Rol', render: (role) => role.name },
              { key: 'permissions', header: 'Permisos', render: (role) => role.permissions.length, align: 'right' },
              { key: 'status', header: 'Estado', render: () => <StatusPill tone="ok">Disponible</StatusPill> },
              { key: 'actions', header: 'Acciones', render: (role) => <>
                {can('roles.update') && <button type="button" className="button button-secondary" disabled={busy}
                  onClick={() => {
                    setForm({ id: role.id, name: role.name, permissions: role.permissions });
                    setRolePanelOpen(true);
                  }}>Editar</button>}
                {can('roles.delete') && <button type="button" className="button button-secondary" disabled={busy}
                  onClick={() => void deactivate(role)}>Desactivar</button>}
              </> },
            ]} />
        </Card>
        {import.meta.env.DEV && <GestM14ReviewIncident branchId={branchId}
          csrfToken={csrfToken} canWrite={can('roles.update')} />}
      </>}
      {canSeeUsers && canSeeRoles && <Card title="Usuarios de la empresa">
        <Toolbar trailing={<span className="ui-count">{visibleMembers.length} de {memberships.length}</span>}>
          <label className="ui-search"><span className="ui-sr-only">Buscar usuario</span>
            <input type="search" placeholder="Buscar nombre o correo de usuario" value={userSearch}
              onChange={(event) => setUserSearch(event.target.value)} />
          </label>
        </Toolbar>
        <DataTable caption="Usuarios y sus roles" rows={visibleMembers}
          rowKey={(member) => member.id}
          onOpen={(member) => setSelectedMemberId(member.id)}
          empty={<><strong>No hay usuarios para mostrar</strong><span>Prueba otra búsqueda o revisa el acceso de la sede.</span></>}
          columns={[
            { key: 'name', header: 'Usuario', render: (member) =>
              <span className="ui-cell-main"><strong>{member.user.name}</strong><small>{member.user.email}</small></span> },
            { key: 'roles', header: 'Roles asignados', render: (member) => member.role_ids.length, align: 'right' },
            { key: 'state', header: 'Acceso', render: (member) =>
              <StatusPill tone={member.tenant_owner ? 'info' : 'neutral'}>
                {member.tenant_owner ? 'Propietario del tenant' : 'Miembro'}</StatusPill> },
          ]} />
      </Card>}
      <SidePanel open={rolePanelOpen && (can('roles.create') ||
        (form.id !== null && can('roles.update')))}
        title={form.id ? 'Editar rol y permisos' : 'Nuevo rol'}
        onClose={() => { setRolePanelOpen(false); setForm(emptyForm); }}>
          <form className="ui-form role-editor" onSubmit={submitRole}>
              <div className="section-heading compact">
                <div>
                  <span className="eyebrow">
                    {form.id ? 'Editar' : 'Nuevo rol'}
                  </span>
                  <h3>
                    {form.id ? 'Ajustar permisos' : 'Crear rol'}
                  </h3>
                </div>
                {form.id && (
                  <button
                    type="button"
                    className="button button-secondary"
                    onClick={() => setForm(emptyForm)}
                  >
                    Cancelar
                  </button>
                )}
              </div>

              <label className="field">
                <span>Nombre del rol</span>
                <input
                  required
                  maxLength={120}
                  value={form.name}
                  onChange={(event) => setForm((current) => ({
                    ...current,
                    name: event.target.value,
                  }))}
                />
              </label>

              <div className="permission-matrix">
                {catalog.map((module) => (
                  <fieldset key={module.key}>
                    <legend>{module.label}</legend>
                    <div className="permission-actions">
                      {module.permissions.map((permission) => (
                        <label key={permission.key}>
                          <input
                            type="checkbox"
                            checked={selected.has(permission.key)}
                            onChange={() => togglePermission(permission.key)}
                          />
                          <span>{permission.label}</span>
                        </label>
                      ))}
                    </div>
                  </fieldset>
                ))}
              </div>

              <button
                className="button"
                type="submit"
                disabled={busy}
              >
                {form.id ? 'Guardar cambios' : 'Crear rol'}
              </button>
            </form>
      </SidePanel>
      <SidePanel open={selectedMember !== undefined && canSeeRoles && canSeeUsers}
        title={selectedMember?.user.name ?? 'Acceso de usuario'}
        onClose={() => setSelectedMemberId(null)}>
        {selectedMember && <>
          <dl className="ui-details">
            <div><dt>Correo</dt><dd>{selectedMember.user.email}</dd></div>
            <div><dt>Tipo de acceso</dt><dd>{selectedMember.tenant_owner
              ? 'Propietario del tenant · acceso total compatible' : 'Miembro de empresa'}</dd></div>
          </dl>
          <fieldset className="permission-matrix">
            <legend>Roles asignados</legend>
            {roles.map((role) => <label key={role.id} className="field">
              <input type="checkbox" checked={selectedMember.role_ids.includes(role.id)}
                disabled={!can('users.update') || selectedMember.tenant_owner || busy}
                onChange={(event) => void toggleAssignment(selectedMember, role, event.target.checked)} />
              <span>{role.name}</span>
            </label>)}
          </fieldset>
        </>}
      </SidePanel>
    </section>
  );
}
