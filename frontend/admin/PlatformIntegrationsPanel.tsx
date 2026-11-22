import { useEffect, useState, type FormEvent } from 'react';
import { Card, PageHeader, StatusPill } from './ui/Kit';

type WompiKey = 'wompi.public_key' | 'wompi.private_key' | 'wompi.integrity_secret' | 'wompi.events_secret';
type IntegrationKey = 'mail.provider' | 'mail.from' | 'mail.api_key' | 'sentry.dsn' | WompiKey;
const wompiFields: ReadonlyArray<{ key: WompiKey; label: string; prefix: string }> = [
  { key: 'wompi.public_key', label: 'Clave pública de prueba', prefix: 'pub_test_' },
  { key: 'wompi.private_key', label: 'Clave privada de prueba', prefix: 'prv_test_' },
  { key: 'wompi.integrity_secret', label: 'Secreto de integridad de prueba', prefix: 'test_integrity_' },
  { key: 'wompi.events_secret', label: 'Secreto de eventos de prueba', prefix: 'test_events_' },
];
type SafeSetting = { configured: boolean; last4: string | null; updated_at: string | null; value?: string };
type IntegrationResponse = {
  settings: Record<IntegrationKey, SafeSetting>;
  csrf_token: string;
  test_email_enabled: boolean;
  secret_write_enabled: boolean;
  sentry_test_enabled: boolean;
  sentry_capture_active: boolean;
  wompi_sandbox_enabled: boolean;
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
  const [sentryEventId, setSentryEventId] = useState('');
  const [wompiInputs, setWompiInputs] = useState<Record<WompiKey, string>>({
    'wompi.public_key': '', 'wompi.private_key': '', 'wompi.integrity_secret': '', 'wompi.events_secret': '',
  });
  const [wompiResult, setWompiResult] = useState<{ reference: string; status: string; transaction_id: string } | null>(null);

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
      setWompiInputs({
        'wompi.public_key': '', 'wompi.private_key': '',
        'wompi.integrity_secret': '', 'wompi.events_secret': '',
      });
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
      if (key === 'sentry.dsn') await load();
    } catch {
      setError('No se pudo guardar el ajuste. Comprueba el dato y vuelve a intentarlo.');
    } finally {
      // Ni siquiera tras un error se conserva la clave en el estado.
      if (key === 'mail.api_key') setApiKey('');
      if (key === 'sentry.dsn') setSentryDsn('');
      if (key.startsWith('wompi.')) {
        setWompiInputs(current => ({ ...current, [key]: '' }));
      }
      setBusy(false);
    }
  }

  async function deleteSecret(key: 'mail.api_key' | 'sentry.dsn' | WompiKey) {
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
      if (key === 'sentry.dsn') await load();
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

  async function sendSentryTest() {
    if (!data?.csrf_token || !data.sentry_test_enabled || !setting('sentry.dsn').configured || busy) return;
    setBusy(true);
    setError('');
    setNotice('');
    setSentryEventId('');
    try {
      const response = await fetch(endpoint + '/test-sentry', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
          'X-CSRF-Token': data.csrf_token },
        body: '{}',
      });
      const result = await response.json() as { event_id?: string; error?: string };
      if (!response.ok || !result.event_id) {
        throw new Error(result.error || 'No se pudo enviar la prueba de Sentry.');
      }
      setSentryEventId(result.event_id);
      setNotice('Evento sintético enviado a Sentry.');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'No se pudo enviar la prueba de Sentry.');
    } finally {
      setBusy(false);
    }
  }

  async function sendWompiTest() {
    if (!data?.csrf_token || !data.wompi_sandbox_enabled || busy
      || !wompiFields.every(field => setting(field.key).configured)) return;
    setBusy(true);
    setError('');
    setNotice('');
    setWompiResult(null);
    try {
      const response = await fetch(endpoint + '/test-wompi', {
        method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': data.csrf_token },
        body: '{}',
      });
      const result = await response.json() as {
        reference?: string; status?: string; transaction_id?: string; error?: string;
      };
      if (!response.ok || !result.reference || !result.status || !result.transaction_id) {
        throw new Error(result.error || 'Wompi sandbox no completó la prueba.');
      }
      setWompiResult({ reference: result.reference, status: result.status, transaction_id: result.transaction_id });
      setNotice('Transacción sintética creada en Wompi sandbox.');
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : 'No fue posible probar Wompi sandbox.');
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
    {data && !denied && <div className="ui-card-grid ui-integrations-grid">
      <Card title="Correo · Resend">
        <div className="ui-integration-content">
        <p className="muted">El envío transaccional usa los ajustes cifrados. Ninguna clave se devuelve al navegador.</p>
        {!data.secret_write_enabled && <p className="muted">La escritura de claves está bloqueada hasta autorización expresa de live.</p>}
        <form className="ui-integration-form" aria-label="Proveedor de correo" onSubmit={event => void save(event, 'mail.provider', provider)}>
          <label className="field">Proveedor
            <select value={provider} onChange={event => setProvider(event.target.value)}><option value="resend">Resend</option></select>
          </label>
          <Detail setting={setting('mail.provider')} />
          <div className="ui-form-actions"><button className="button" type="submit" disabled={busy}>Guardar proveedor</button></div>
        </form>
        <form className="ui-integration-form" aria-label="Remitente de correo" onSubmit={event => void save(event, 'mail.from', from)}>
          <label className="field">Remitente
            <input type="email" autoComplete="off" value={from} onChange={event => setFrom(event.target.value)} required placeholder="correo@empresa.com" />
          </label>
          <Detail setting={setting('mail.from')} />
          <div className="ui-form-actions"><button className="button" type="submit" disabled={busy}>Guardar remitente</button></div>
        </form>
        <form className="ui-integration-form" aria-label="Clave API de correo" onSubmit={event => void save(event, 'mail.api_key', apiKey)}>
          <label className="field">Clave de API · solo escritura
            <input type="password" autoComplete="new-password" value={apiKey} onChange={event => setApiKey(event.target.value)} minLength={8} required />
          </label>
          <Detail setting={setting('mail.api_key')} />
          <div className="ui-form-actions">
            <button className="button" type="submit" disabled={busy || !data.secret_write_enabled}>Guardar {field.label}</button>
            <button className="button button-secondary" type="button" disabled={busy || !setting('mail.api_key').configured}
              onClick={() => void deleteSecret('mail.api_key')}>Eliminar clave</button>
          </div>
        </form>
        <form className="ui-integration-form" aria-label="Correo de prueba" onSubmit={event => void sendTest(event)}>
          <label className="field">Destino del correo de prueba
            <input type="email" value={testRecipient} onChange={event => setTestRecipient(event.target.value)} required placeholder="destino@empresa.com" />
          </label>
          <div className="ui-form-actions">
            <button className="button button-secondary" type="submit" disabled={busy || !data.test_email_enabled}>
              Enviar correo de prueba
            </button>
          </div>
          {!data.test_email_enabled && <p className="muted">Envío de prueba deshabilitado hasta autorización expresa de live.</p>}
          <p className="muted">Máximo uno por minuto, aplicado por el servidor.</p>
        </form>
        </div>
      </Card>
      <Card title="Observabilidad · Sentry">
        <div className="ui-integration-content">
        <p className="muted">Captura Sentry: {data.sentry_capture_active
          ? 'activa (DSN configurado y entorno prod)'
          : 'inactiva (requiere DSN guardado y entorno prod)'}.</p>
        {!data.secret_write_enabled && <p className="muted">La escritura del DSN requiere habilitación explícita del propietario.</p>}
        <form className="ui-integration-form" aria-label="Observabilidad Sentry" onSubmit={event => void save(event, 'sentry.dsn', sentryDsn)}>
          <label className="field">DSN de Sentry · solo escritura
            <input type="password" autoComplete="new-password" value={sentryDsn} onChange={event => setSentryDsn(event.target.value)} minLength={8} required placeholder="https://…" />
          </label>
          <Detail setting={setting('sentry.dsn')} />
          <div className="ui-form-actions">
            <button className="button" type="submit" disabled={busy || !data.secret_write_enabled}>Guardar DSN</button>
            <button className="button button-secondary" type="button" disabled={busy || !setting('sentry.dsn').configured}
              onClick={() => void deleteSecret('sentry.dsn')}>Eliminar DSN</button>
          </div>
        </form>
        <div className="ui-integration-form" aria-label="Prueba de Sentry">
          <div className="ui-form-actions">
            <button className="button button-secondary" type="button"
              disabled={busy || !data.sentry_test_enabled || !setting('sentry.dsn').configured}
              onClick={() => void sendSentryTest()}>Enviar evento de prueba</button>
          </div>
          {!data.sentry_test_enabled && <p className="muted">Prueba de Sentry deshabilitada por configuración.</p>}
          {sentryEventId && <output role="status">ID del evento Sentry: {sentryEventId}</output>}
          <p className="muted">Evento sintético sin datos personales. Máximo uno por minuto.</p>
        </div>
        </div>
      </Card>
      <Card title="Pagos">
        <div className="ui-integration-content">
          <p className="muted">Solo claves test; no se aceptan claves de producción ni se realizan cobros reales.</p>
          {!data.wompi_sandbox_enabled && <p className="muted">Pruebas de pago deshabilitadas por configuración.</p>}
          {!data.secret_write_enabled && <p className="muted">La escritura de secretos requiere habilitación del propietario.</p>}
          {wompiFields.map(field => <form key={field.key} className="ui-integration-form"
            aria-label={field.label} onSubmit={event => void save(event, field.key, wompiInputs[field.key])}>
            <label className="field">{field.label} · solo escritura
              <input type="password" autoComplete="new-password" value={wompiInputs[field.key]}
                onChange={event => setWompiInputs(current => ({ ...current, [field.key]: event.target.value }))}
                pattern={field.prefix + '[A-Za-z0-9_-]{16,}'} required placeholder={field.prefix + '…'} />
            </label>
            <Detail setting={setting(field.key)} />
            <div className="ui-form-actions">
              <button className="button" type="submit" disabled={busy || !data.secret_write_enabled}>Guardar clave</button>
              <button className="button button-secondary" type="button"
                disabled={busy || !setting(field.key).configured}
                onClick={() => void deleteSecret(field.key)}>Eliminar clave</button>
            </div>
          </form>)}
          <div className="ui-integration-form" aria-label="Prueba de pago Wompi sandbox">
            <div className="ui-form-actions">
              <button className="button button-secondary" type="button"
                disabled={busy || !data.wompi_sandbox_enabled || !wompiFields.every(field => setting(field.key).configured)}
                onClick={() => void sendWompiTest()}>Probar pago sandbox</button>
            </div>
            {wompiResult && <output role="status">
              Referencia: {wompiResult.reference} · Estado: {wompiResult.status}
              {' '}· Transacción: {wompiResult.transaction_id}
            </output>}
            <p className="muted">Pago sintético NEQUI de COP 1.500; máximo uno por minuto.</p>
          </div>
        </div>
      </Card>
      <Card title="Almacenamiento"><div className="ui-integration-content"><StatusPill>Próximamente</StatusPill><p className="muted">La gestión de proveedores de archivos aún no está habilitada.</p></div></Card>
    </div>}
  </section>;
}
