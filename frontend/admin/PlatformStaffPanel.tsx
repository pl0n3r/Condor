import { FormEvent, useEffect, useMemo, useState } from 'react';
import {
  platformStaffGrantsPath,
  platformStaffInvitationPath,
  platformStaffInvitationResendPath,
  platformStaffInvitationRevokePath,
  platformStaffPath,
} from './api';

type TenantOption = {
  id: string;
  name: string;
  slug: string;
};

type PermissionDefinition = {
  key: string;
  label: string;
  permissions: Array<{
    key: string;
    action: string;
    label: string;
  }>;
};

type GrantDefinition = {
  tenant_id: string | null;
  module: string;
  actions: string[];
};

type StaffRow = {
  id: string;
  name: string;
  email: string;
  active: boolean;
  invitation: {
    id: string;
    state: string;
    expires_at: string;
  };
  grants: GrantDefinition[];
};

type StaffResponse = {
  staff: StaffRow[];
  catalog: PermissionDefinition[];
  tenant_options: TenantOption[];
  tenant_options_truncated: boolean;
};

type PlatformStaffPanelProps = Readonly<{
  csrfToken: string;
  refreshKey: number;
}>;

export function PlatformStaffPanel({
  csrfToken,
  refreshKey,
}: PlatformStaffPanelProps) {
  const [data, setData] = useState<StaffResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState('');
  const [activationPath, setActivationPath] = useState('');
  const [email, setEmail] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [scope, setScope] = useState('*');
  const [moduleKey, setModuleKey] = useState('');
  const [actions, setActions] = useState<string[]>([]);
  const [draftGrants, setDraftGrants] = useState<GrantDefinition[]>([]);
  const [editingStaffId, setEditingStaffId] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const tenantNames = useMemo(() => {
    const names = new Map<string, string>();
    for (const tenant of data?.tenant_options ?? []) {
      names.set(tenant.id, tenant.name);
    }
    return names;
  }, [data]);

  async function load() {
    setLoading(true);
    try {
      const response = await fetch(platformStaffPath(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error('No fue posible cargar el staff de plataforma.');
      }
      const payload = await response.json() as StaffResponse;
      setData(payload);
      setModuleKey((current) => current || payload.catalog[0]?.key || '');
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : 'No fue posible cargar el staff de plataforma.',
      );
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void load();
  }, [refreshKey]);

  function toggleAction(action: string) {
    setActions((current) => (
      current.includes(action)
        ? current.filter((candidate) => candidate !== action)
        : [...current, action].sort((left, right) => left.localeCompare(right))
    ));
  }

  function addGrant() {
    if (!moduleKey || actions.length === 0) {
      setMessage('Selecciona un módulo y al menos una acción CRUD.');
      return;
    }

    const tenantId = scope === '*' ? null : scope;
    const next: GrantDefinition = {
      tenant_id: tenantId,
      module: moduleKey,
      actions: [...actions].sort((left, right) => left.localeCompare(right)),
    };

    setDraftGrants((current) => {
      const filtered = current.filter(
        (grant) => (
          grant.tenant_id !== next.tenant_id ||
          grant.module !== next.module
        ),
      );
      return [...filtered, next];
    });
    setActions([]);
    setMessage('');
  }

  function removeGrant(index: number) {
    setDraftGrants((current) => (
      current.filter((_, candidate) => candidate !== index)
    ));
  }

  function resetEditor() {
    setEditingStaffId(null);
    setEmail('');
    setDisplayName('');
    setDraftGrants([]);
    setActions([]);
    setScope('*');
  }

  function editStaff(staff: StaffRow) {
    setEditingStaffId(staff.id);
    setEmail(staff.email);
    setDisplayName(staff.name);
    setDraftGrants(staff.grants);
    setActivationPath('');
    setMessage(
      'Editando permisos de ' + staff.name + '.',
    );
  }

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
      const response = await fetch(
        editing
          ? platformStaffGrantsPath(editingStaffId)
          : platformStaffInvitationPath(),
        {
          method: editing ? 'PUT' : 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify(
            editing
              ? { grants: draftGrants }
              : {
                  email,
                  display_name: displayName,
                  grants: draftGrants,
                },
          ),
        },
      );

      if (!response.ok) {
        throw new Error(
          response.status === 422
            ? 'Revisa el correo, el alcance y los permisos seleccionados.'
            : 'No fue posible guardar el staff de plataforma.',
        );
      }

      const payload = await response.json() as {
        invitation?: { activation_path_once?: string };
      };

      if (payload.invitation?.activation_path_once) {
        setActivationPath(payload.invitation.activation_path_once);
      }
      setMessage(
        editing
          ? 'Permisos actualizados.'
          : 'Staff creado y pendiente de activación.',
      );
      resetEditor();
      await load();
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : 'No fue posible guardar el staff de plataforma.',
      );
    } finally {
      setSaving(false);
    }
  }

  async function invitationAction(
    staff: StaffRow,
    action: 'resend' | 'revoke',
  ) {
    setMessage('');
    setActivationPath('');

    const response = await fetch(
      action === 'resend'
        ? platformStaffInvitationResendPath(staff.id)
        : platformStaffInvitationRevokePath(staff.id),
      {
        method: action === 'resend' ? 'POST' : 'DELETE',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken,
        },
      },
    );

    if (!response.ok) {
      setMessage('No fue posible actualizar la invitación.');
      return;
    }

    if (action === 'resend') {
      const payload = await response.json() as {
        invitation: { activation_path_once: string };
      };
      setActivationPath(payload.invitation.activation_path_once);
      setMessage('Invitación regenerada. El token anterior quedó inválido.');
    } else {
      setMessage('Invitación revocada.');
    }
    await load();
  }

  function scopeLabel(grant: GrantDefinition): string {
    return grant.tenant_id === null
      ? 'Todos los clientes'
      : tenantNames.get(grant.tenant_id) ?? 'Cliente ' + grant.tenant_id;
  }

  function moduleLabel(key: string): string {
    return data?.catalog.find((module) => module.key === key)?.label ?? key;
  }

  let submitLabel = 'Crear staff e invitar';
  if (saving) {
    submitLabel = 'Guardando…';
  } else if (editingStaffId) {
    submitLabel = 'Guardar permisos';
  }

  return (
    <section className="platform-section" aria-labelledby="platform-staff-title">
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Equipo Condor</span>
          <h2 id="platform-staff-title">Staff de plataforma</h2>
        </div>
        <span className="muted">
          Cliente → módulo → CRUD
        </span>
      </div>

      {loading && (
        <output className="access-message" aria-live="polite">
          Cargando staff…
        </output>
      )}

      {data && (
        <div className="platform-staff-layout">
          <form className="platform-form" onSubmit={(event) => void submit(event)}>
            <h3>{editingStaffId ? 'Editar permisos' : 'Invitar staff'}</h3>

            <div className="platform-form-grid">
              <label className="field">
                <span>Nombre</span>
                <input
                  required={!editingStaffId}
                  disabled={editingStaffId !== null}
                  value={displayName}
                  onChange={(event) => setDisplayName(event.target.value)}
                />
              </label>
              <label className="field">
                <span>Correo</span>
                <input
                  required={!editingStaffId}
                  disabled={editingStaffId !== null}
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                />
              </label>
            </div>

            <div className="grant-builder">
              <label className="field">
                <span>Cliente</span>
                <select
                  aria-label="Cliente"
                  value={scope}
                  onChange={(event) => setScope(event.target.value)}
                >
                  <option value="*">Todos los clientes</option>
                  {data.tenant_options.map((tenant) => (
                    <option value={tenant.id} key={tenant.id}>
                      {tenant.name}
                    </option>
                  ))}
                </select>
              </label>

              <label className="field">
                <span>Módulo</span>
                <select
                  aria-label="Módulo"
                  value={moduleKey}
                  onChange={(event) => {
                    setModuleKey(event.target.value);
                    setActions([]);
                  }}
                >
                  {data.catalog.map((module) => (
                    <option value={module.key} key={module.key}>
                      {module.label}
                    </option>
                  ))}
                </select>
              </label>

              <fieldset className="crud-picker">
                <legend>CRUD permitido</legend>
                {data.catalog
                  .find((module) => module.key === moduleKey)
                  ?.permissions.map((permission) => (
                    <label key={permission.action}>
                      <input
                        type="checkbox"
                        checked={actions.includes(permission.action)}
                        onChange={() => toggleAction(permission.action)}
                      />
                      {permission.label}
                    </label>
                  ))}
              </fieldset>

              <button
                className="button button-secondary"
                type="button"
                onClick={addGrant}
              >
                Añadir permiso
              </button>
            </div>

            {data.tenant_options_truncated && (
              <p className="muted">
                Se muestran los primeros 50 clientes. La búsqueda ampliada
                se habilitará cuando el volumen lo requiera.
              </p>
            )}

            <div className="grant-draft" aria-label="Permisos seleccionados">
              {draftGrants.length === 0 ? (
                <span className="muted">Aún no hay permisos seleccionados.</span>
              ) : (
                draftGrants.map((grant, index) => (
                  <div
                    className="grant-row"
                    key={
                      (grant.tenant_id ?? '*') +
                      '-' +
                      grant.module
                    }
                  >
                    <div>
                      <strong>{scopeLabel(grant)}</strong>
                      <span>
                        {moduleLabel(grant.module)} ·{' '}
                        {grant.actions.join(', ')}
                      </span>
                    </div>
                    <button
                      className="button button-secondary"
                      type="button"
                      onClick={() => removeGrant(index)}
                    >
                      Quitar
                    </button>
                  </div>
                ))
              )}
            </div>

            <div className="platform-form-actions">
              <button className="button" type="submit" disabled={saving}>
                {submitLabel}
              </button>
              {editingStaffId && (
                <button
                  className="button button-secondary"
                  type="button"
                  onClick={resetEditor}
                >
                  Cancelar
                </button>
              )}
            </div>
          </form>

          <div className="staff-list" aria-label="Staff registrado">
            {data.staff.length === 0 ? (
              <div className="platform-empty">
                Aún no hay staff de plataforma.
              </div>
            ) : (
              data.staff.map((staff) => (
                <article className="staff-card" key={staff.id}>
                  <div className="staff-card-heading">
                    <div>
                      <strong>{staff.name}</strong>
                      <span>{staff.email}</span>
                    </div>
                    <span className="status-pill">
                      {staff.active
                        ? 'Activo'
                        : staff.invitation.state}
                    </span>
                  </div>

                  <div className="staff-grants">
                    {staff.grants.map((grant) => (
                      <div
                        className="staff-grant"
                        key={
                          (grant.tenant_id ?? '*') +
                          '-' +
                          grant.module
                        }
                      >
                        <strong>{scopeLabel(grant)}</strong>
                        <span>
                          {moduleLabel(grant.module)} ·{' '}
                          {grant.actions.join(', ')}
                        </span>
                      </div>
                    ))}
                  </div>

                  <div className="role-actions">
                    <button
                      className="button button-secondary"
                      type="button"
                      onClick={() => editStaff(staff)}
                    >
                      Editar permisos
                    </button>
                    {!staff.active && (
                      <>
                        <button
                          className="button button-secondary"
                          type="button"
                          onClick={() => (
                            void invitationAction(staff, 'resend')
                          )}
                        >
                          Reemitir invitación
                        </button>
                        <button
                          className="button button-secondary"
                          type="button"
                          onClick={() => (
                            void invitationAction(staff, 'revoke')
                          )}
                        >
                          Revocar invitación
                        </button>
                      </>
                    )}
                  </div>
                </article>
              ))
            )}
          </div>
        </div>
      )}

      {message && (
        <output className="access-message" aria-live="polite">
          {message}
        </output>
      )}

      {activationPath && (
        <div className="activation-once" role="note">
          <strong>Enlace de activación · mostrar una sola vez</strong>
          <code>{activationPath}</code>
          <span>
            El token anterior no se almacena en texto plano ni vuelve a
            aparecer en el listado.
          </span>
        </div>
      )}
    </section>
  );
}
