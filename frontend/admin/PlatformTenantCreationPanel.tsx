import { FormEvent, useState } from 'react';
import { platformTenantsPath } from './api';

type PlatformTenantCreationPanelProps = Readonly<{
  csrfToken: string;
  onCreated: () => void;
}>;

type CreatedTenant = {
  tenant: { id: string; name: string; slug: string };
  branch: { id: string; name: string; slug: string };
  owner: { id: string; name: string; email: string; active: boolean };
  invitation: {
    id: string;
    expires_at: string;
    activation_path_once: string;
    delivery: string;
  };
};

type FormState = {
  name: string;
  slug: string;
  legalName: string;
  nit: string;
  branchName: string;
  ownerEmail: string;
  ownerName: string;
};

const initialForm: FormState = {
  name: '',
  slug: '',
  legalName: '',
  nit: '',
  branchName: 'Principal',
  ownerEmail: '',
  ownerName: '',
};

export function PlatformTenantCreationPanel({
  csrfToken,
  onCreated,
}: PlatformTenantCreationPanelProps) {
  const [form, setForm] = useState<FormState>(initialForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');
  const [activationPath, setActivationPath] = useState('');

  function update<K extends keyof FormState>(
    key: K,
    value: FormState[K],
  ) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage('');
    setActivationPath('');

    try {
      const response = await fetch(platformTenantsPath(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({
          name: form.name,
          slug: form.slug,
          legal_name: form.legalName,
          nit: form.nit || null,
          branch_name: form.branchName,
          owner_email: form.ownerEmail,
          owner_name: form.ownerName,
        }),
      });

      if (!response.ok) {
        throw new Error(
          response.status === 422
            ? 'Revisa los datos: la empresa o el correo ya pueden existir.'
            : 'No fue posible crear la empresa.',
        );
      }

      const created = await response.json() as CreatedTenant;
      setMessage(
        created.tenant.name +
          ' fue creada. El administrador quedó pendiente de activación.',
      );
      setActivationPath(created.invitation.activation_path_once);
      setForm(initialForm);
      onCreated();
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : 'No fue posible crear la empresa.',
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <section
      className="platform-section"
      aria-labelledby="new-tenant-title"
    >
      <div className="section-heading compact">
        <div>
          <span className="eyebrow">Empresas</span>
          <h2 id="new-tenant-title">Crear cliente</h2>
        </div>
        <span className="muted">
          Empresa + sede principal + invitación del administrador
        </span>
      </div>

      <form className="platform-form" onSubmit={(event) => void submit(event)}>
        <div className="platform-form-grid">
          <label className="field">
            <span>Nombre comercial</span>
            <input
              required
              maxLength={160}
              value={form.name}
              onChange={(event) => update('name', event.target.value)}
            />
          </label>

          <label className="field">
            <span>Slug</span>
            <input
              required
              maxLength={120}
              pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
              placeholder="mi-empresa"
              value={form.slug}
              onChange={(event) => update('slug', event.target.value)}
            />
          </label>

          <label className="field">
            <span>Razón social</span>
            <input
              required
              maxLength={180}
              value={form.legalName}
              onChange={(event) => update('legalName', event.target.value)}
            />
          </label>

          <label className="field">
            <span>NIT · opcional</span>
            <input
              maxLength={32}
              value={form.nit}
              onChange={(event) => update('nit', event.target.value)}
            />
          </label>

          <label className="field">
            <span>Sede inicial</span>
            <input
              required
              maxLength={160}
              value={form.branchName}
              onChange={(event) => update('branchName', event.target.value)}
            />
          </label>

          <label className="field">
            <span>Nombre del administrador</span>
            <input
              required
              maxLength={160}
              value={form.ownerName}
              onChange={(event) => update('ownerName', event.target.value)}
            />
          </label>

          <label className="field platform-form-wide">
            <span>Correo del administrador</span>
            <input
              required
              type="email"
              maxLength={180}
              value={form.ownerEmail}
              onChange={(event) => update('ownerEmail', event.target.value)}
            />
          </label>
        </div>

        <div className="platform-form-actions">
          <button className="button" type="submit" disabled={saving}>
            {saving ? 'Creando…' : 'Crear empresa e invitar admin'}
          </button>
          <span className="muted">
            Condor no define la contraseña del cliente.
          </span>
        </div>
      </form>

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
            Hasta conectar el transporte de email, comparte este enlace
            por un canal seguro.
          </span>
        </div>
      )}
    </section>
  );
}
