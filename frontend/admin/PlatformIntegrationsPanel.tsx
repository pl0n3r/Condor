import { useEffect, useState, type FormEvent } from 'react';
import { Card, PageHeader, StatusPill } from './ui/Kit';

type IntegrationKey = 'mail.provider' | 'mail.from' | 'mail.api_key' | 'sentry.dsn';
type SafeSetting = { configured: boolean; last4: string | null; updated_at: string | null; value?: string };
type IntegrationResponse = {
  settings: Record<IntegrationKey, SafeSetting>;
  csrf_token: string;
  test_email_enabled: boolean;
  secret_write_enabled: boolean;
};
type Props = Readonly<{ refreshKey: number }>;

const endpoint = '/adminpl0n3r/api/integrations';
const empty: SafeSetting = { configured: false, last4: null, updated_at: null };

function Detail({ setting }: Readonly<{ setting: SafeSetting }>) {
  return <div className="ui-integration-status">
    <StatusPill tone={setting.configured ? 'ok' : 'warn'}>
      {setting.configured ? 'Configurado' : 'Sin configurar'}
    </StatusPill>
    {setting.last4 && <span>Termina en {setting.last4}</span>}
    {setting.updated_at && <span>Actualizado: {setting.updated_at}</span>}
  </div>;
}

/** Nunca hidrata inputs secretos desde el GET ni escribe secrets en el DOM tras guardar. */
export function PlatformIntegrationsPanel({ refreshKey }: Props) {
  const [data, setData] = useState<IntegrationResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [denied, setDenied] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [provider, setProvider] = useState('resend');
  const [from, setFrom] = useState('');
  const [apiKey, setApiKey] = useState('');
  const [sentryDsn, setSentryDsn] = useState('');
  const [testRecipient, setTestRecipient] = useState('');

  async function load() {
    setLoading(true);
    setError('');
    setDenied(false);
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (response.status === 401 || response.status === 403) {
        setDenied(true);
        setData(null);
        return;
      }
      if (!response.ok) throw new Error();
      const next = await response.json() as IntegrationResponse;
      if (!next.settings || !next.csrf_token) throw new Error();
      setData(next);
      setProvider(next.settings['mail.provider']?.value || 'resend');
      setFrom(next.settings['mail.from']?.value || '');
      // Intencionadamente NUNCA se carga un secreto en los formularios.
      setApiKey('');
      setSentryDsn('');
    } catch {
      setData(null);
      setError('No fue posible consultar las integraciones.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { void load(); }, [refreshKey]);

  async function save(event: FormEvent<HTMLFormElement>, key: IntegrationKey, value: string) {
    event.preventDefault();
    if (!data?.csrf_token || busy) return;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetch(endpoint, {
        method: 'PUT', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf_token },
        body: JSON.stringify({ key, value }),
      });
      if (response.status === 401 || response.status === 403) {
        setDenied(true);
        return;
      }
      if (!response.ok) throw new Error();
      const safe = await response.json() as { key: IntegrationKey; setting: SafeSetting };
      setData(current => current ? { ...current, settings: { ...current.settings, [key]: safe.setting } } : current);
      setNotice('Ajuste guardado.');
    } catch {
      setError('No se pudo guardar el ajuste. Comprueba el dato y vuelve a intentarlo.');
    } finally {
      // Ni siquiera tras un error se conserva la clave en el estado.
      if (key === 'mail.api_key') setApiKey('');
      if (key === 'sentry.dsn') setSentryDsn('');
      setBusy(false);
    }
  }

  async function deleteSecret(key: 'mail.api_key' | 'sentry.dsn') {
    if (!data?.csrf_token || busy) return;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetch(endpoint + '/secret/' + key, {
        method: 'DELETE', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': data.csrf_token },
      });
      if (response.status === 401 || response.status === 403) {
        setDenied(true);
        return;
      }
      if (!response.ok) throw new Error();
      const safe = await response.json() as { setting: SafeSetting };
      setData(current => current ? { ...current, settings: { ...current.settings, [key]: safe.setting } } : current);
      setNotice('Secreto eliminado.');
    } catch {
      setError('No se pudo eliminar el secreto.');
    } finally {
      setBusy(false);
    }
  }

  async function sendTest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!data?.csrf_token || !data.test_email_enabled || busy) return;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const response = await fetch(endpoint + '/test-email', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf_token },
        body: JSON.stringify({ email: testRecipient }),
      });
      if (!response.ok) throw new Error();
      setNotice('Solicitud de correo de prueba completada.');
    } catch {
      setError('No se pudo entregar el correo de prueba o se alcanzó el límite de un minuto.');
    } finally {
      setBusy(false);
    }
  }

  const setting = (key: IntegrationKey) => data?.settings[key] ?? empty;

  return <section className="ui-page platform-section" aria-label="Integraciones de plataforma">
    <PageHeader title="Integraciones" description="Configuración global exclusiva del propietario. Los secretos se escriben, pero nunca se muestran." actions={
      <button className="button button-secondary" type="button" onClick={() => void load()} disabled={busy}>Actualizar</button>
    } />
    {loading && <output role="status">Cargando integraciones…</output>}
    {denied && <div role="alert" className="alert alert-error">No tienes permisos para gestionar integraciones de plataforma.</div>}
    {error && <div role="alert" className="alert alert-error">{error}</div>}
    {notice && <output role="status" className="alert">{notice}</output>}
    {data && !denied && <div className="ui-card-grid">
      <Card title="Correo · Resend">
        <p className="muted">El envío transaccional usa los ajustes cifrados. Ninguna clave se devuelve al navegador.</p>
        {!data.secret_write_enabled && <p className="muted">La escritura de claves está bloqueada hasta autorización expresa de live.</p>}
        <form onSubmit={event => void save(event, 'mail.provider', provider)}>
          <label className="field">Proveedor
            <select value={provider} onChange={event => setProvider(event.target.value)}><option value="resend">Resend</option></select>
          </label>
          <Detail setting={setting('mail.provider')} />
          <button className="button" type="submit" disabled={busy}>Guardar proveedor</button>
        </form>
        <form onSubmit={event => void save(event, 'mail.from', from)}>
          <label className="field">Remitente
            <input type="email" autoComplete="off" value={from} onChange={event => setFrom(event.target.value)} required placeholder="correo@empresa.com" />
          </label>
          <Detail setting={setting('mail.from')} />
          <button className="button" type="submit" disabled={busy}>Guardar remitente</button>
        </form>
        <form onSubmit={event => void save(event, 'mail.api_key', apiKey)}>
          <label className="field">Clave de API · solo escritura
            <input type="password" autoComplete="new-password" value={apiKey} onChange={event => setApiKey(event.target.value)} minLength={8} required />
          </label>
          <Detail setting={setting('mail.api_key')} />
          <button className="button" type="submit" disabled={busy || !data.secret_write_enabled}>Guardar clave</button>
          <button className="button button-secondary" type="button" disabled={busy || !setting('mail.api_key').configured}
            onClick={() => void deleteSecret('mail.api_key')}>Eliminar clave</button>
        </form>
        <form onSubmit={event => void sendTest(event)}>
          <label className="field">Destino del correo de prueba
            <input type="email" value={testRecipient} onChange={event => setTestRecipient(event.target.value)} required placeholder="destino@empresa.com" />
          </label>
          <button className="button button-secondary" type="submit" disabled={busy || !data.test_email_enabled}>
            Enviar correo de prueba
          </button>
          {!data.test_email_enabled && <p className="muted">Envío de prueba deshabilitado hasta autorización expresa de live.</p>}
          <p className="muted">Máximo uno por minuto, aplicado por el servidor.</p>
        </form>
      </Card>
      <Card title="Observabilidad · Sentry">
        <p className="muted">La captura Sentry permanece apagada hasta live.</p>
        {!data.secret_write_enabled && <p className="muted">La escritura del DSN requiere autorización expresa de live.</p>}
        <form onSubmit={event => void save(event, 'sentry.dsn', sentryDsn)}>
          <label className="field">DSN de Sentry · solo escritura
            <input type="password" autoComplete="new-password" value={sentryDsn} onChange={event => setSentryDsn(event.target.value)} minLength={8} required placeholder="https://…" />
          </label>
          <Detail setting={setting('sentry.dsn')} />
          <button className="button" type="submit" disabled={busy || !data.secret_write_enabled}>Guardar DSN</button>
          <button className="button button-secondary" type="button" disabled={busy || !setting('sentry.dsn').configured}
            onClick={() => void deleteSecret('sentry.dsn')}>Eliminar DSN</button>
        </form>
      </Card>
      <Card title="Pagos"><StatusPill>Próximamente</StatusPill><p className="muted">La pasarela aún no está habilitada.</p></Card>
      <Card title="Almacenamiento"><StatusPill>Próximamente</StatusPill><p className="muted">La gestión de proveedores de archivos aún no está habilitada.</p></Card>
    </div>}
  </section>;
}
