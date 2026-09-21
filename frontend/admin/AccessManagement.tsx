import { FormEvent, useEffect, useMemo, useState } from 'react';
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

  if (!canSeeRoles && !canSeeUsers) {
    return (
      <section
        id="roles"
        className="access-panel"
        aria-labelledby="access-title"
      >
        <h2 id="access-title">Roles y permisos</h2>
        <p className="muted">
          No tienes permisos para consultar la administración de accesos
          en esta sede.
        </p>
      </section>
    );
  }

  return (
    <section
      id="roles"
      className="access-panel"
      aria-labelledby="access-title"
    >
      <div className="section-heading">
        <div>
          <span className="eyebrow">Acceso por sede</span>
          <h2 id="access-title">Roles y permisos</h2>
        </div>
        <p className="muted">
          Los permisos se acumulan cuando una persona tiene varios roles.
        </p>
      </div>

      {message && (
        <output className="access-message" aria-live="polite">
          {message}
        </output>
      )}

      {canSeeRoles && (
        <div className="access-layout">
          <div>
            <h3>Roles activos</h3>
            <div className="role-list">
              {roles.map((role) => (
                <article className="role-card" key={role.id}>
                  <div>
                    <strong>{role.name}</strong>
                    <span>{role.permissions.length} permisos</span>
                  </div>
                  <div className="role-actions">
                    {can('roles.update') && (
                      <button
                        className="button button-secondary"
                        type="button"
                        onClick={() => setForm({
                          id: role.id,
                          name: role.name,
                          permissions: role.permissions,
                        })}
                      >
                        Editar
                      </button>
                    )}
                    {can('roles.delete') && (
                      <button
                        className="button button-secondary"
                        type="button"
                        onClick={() => void deactivate(role)}
                      >
                        Desactivar
                      </button>
                    )}
                  </div>
                </article>
              ))}
              {roles.length === 0 && (
                <p className="muted">Aún no hay roles configurados.</p>
              )}
            </div>
          </div>

          {(can('roles.create') ||
            (form.id !== null && can('roles.update'))) && (
            <form className="role-editor" onSubmit={submitRole}>
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
          )}
        </div>
      )}

      {canSeeUsers && canSeeRoles && (
        <div className="membership-section">
          <div className="section-heading">
            <div>
              <span className="eyebrow">Asignación</span>
              <h3>Usuarios de la empresa</h3>
            </div>
          </div>

          <div className="membership-list">
            {memberships.map((member) => (
              <article className="membership-card" key={member.id}>
                <div>
                  <strong>{member.user.name}</strong>
                  <span>{member.user.email}</span>
                  {member.tenant_owner && (
                    <small>
                      Propietario del tenant · acceso total compatible
                    </small>
                  )}
                </div>
                <div className="membership-roles">
                  {roles.map((role) => {
                    const checked = member.role_ids.includes(role.id);

                    return (
                      <label key={role.id}>
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={
                            !can('users.update') ||
                            member.tenant_owner ||
                            busy
                          }
                          onChange={(event) => void toggleAssignment(
                            member,
                            role,
                            event.target.checked,
                          )}
                        />
                        <span>{role.name}</span>
                      </label>
                    );
                  })}
                </div>
              </article>
            ))}
          </div>
        </div>
      )}
    </section>
  );
}
