import { FormEvent, useEffect, useRef, useState } from 'react';
import { inventoryPath } from './api';

type Notification = { status?: string; created_at?: string };
type SeoSettings = {
  indexnow: { enabled: boolean; last_notifications: Notification[] };
  gtm: { container_id: string | null; enabled: boolean };
};
type Props = Readonly<{ branchId: string; permissions: string[]; csrfToken: string }>;
type LoadState = { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; data: SeoSettings };

const GTM_ID = /^GTM-[A-Z0-9]+$/;

function record(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function readSettings(value: unknown): SeoSettings {
  if (!record(value) || !record(value.indexnow) || !record(value.gtm)
    || typeof value.indexnow.enabled !== 'boolean'
    || !Array.isArray(value.indexnow.last_notifications)
    || !value.indexnow.last_notifications.every((entry: unknown) => record(entry)
      && (entry.status === undefined || typeof entry.status === 'string')
      && (entry.created_at === undefined || typeof entry.created_at === 'string'))
    || (value.gtm.container_id !== null
      && (typeof value.gtm.container_id !== 'string'
        || !GTM_ID.test(value.gtm.container_id)))
    || typeof value.gtm.enabled !== 'boolean'
    || (value.gtm.enabled && value.gtm.container_id === null)) {
    throw new Error('Los ajustes SEO recibidos no son válidos para esta sede.');
  }
  return value as SeoSettings;
}

function seoSettingsPath(branchId: string): string {
  // El helper de API valida el identificador ULID de la sede.
  return inventoryPath(branchId).replace(/\/inventory$/, '/seo/settings');
}

function requestError(status: number): Error {
  if (status === 401 || status === 403) {
    return new Error('No tienes permiso para consultar o guardar los ajustes SEO.');
  }
  if (status === 404) return new Error('La sede solicitada no está disponible.');
  if (status === 409) return new Error('La configuración cambió. Recarga antes de guardar.');
  if (status === 422 || status === 400) {
    return new Error('La configuración no fue aceptada. Revisa el identificador GTM.');
  }
  return new Error('No fue posible comunicar los ajustes SEO con el servidor.');
}

async function requestSettings(branchId: string, csrfToken: string,
  change?: { indexnow?: { enabled: boolean };
    gtm?: { container_id?: string | null; enabled?: boolean } }): Promise<SeoSettings> {
  const response = await fetch(seoSettingsPath(branchId), {
    method: change === undefined ? 'GET' : 'PUT',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(change === undefined ? {} : {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      }),
    },
    ...(change === undefined ? {} : { body: JSON.stringify(change) }),
  });
  if (!response.ok) throw requestError(response.status);
  try {
    return readSettings(await response.json() as unknown);
  } catch (error) {
    if (error instanceof Error) throw error;
    throw new Error('El servidor devolvió una respuesta no válida.');
  }
}

export function SeoSettingsPanel({ branchId, permissions, csrfToken }: Props) {
  const canView = permissions.includes('settings.view');
  const canUpdate = permissions.includes('settings.update');
  const [state, setState] = useState<LoadState>({ status: 'loading' });
  const [gtmInput, setGtmInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const requestId = useRef(0);

  async function load() {
    if (!canView) return;
    const attempt = ++requestId.current;
    setState({ status: 'loading' });
    setNotice(null);
    try {
      const data = await requestSettings(branchId, csrfToken);
      if (attempt !== requestId.current) return;
      setState({ status: 'ready', data });
      setGtmInput(data.gtm.container_id ?? '');
    } catch (error) {
      if (attempt !== requestId.current) return;
      setState({ status: 'error', message: error instanceof Error
        ? error.message : 'No fue posible cargar los ajustes SEO.' });
    }
  }

  useEffect(() => {
    setGtmInput('');
    setNotice(null);
    if (canView) void load();
    return () => { requestId.current += 1; };
  }, [branchId, canView]);

  const ready = state.status === 'ready' ? state.data : null;
  const normalizedGtm = gtmInput.trim().toUpperCase();
  const validGtm = normalizedGtm === '' || GTM_ID.test(normalizedGtm);
  const gtmChanged = ready !== null
    && normalizedGtm !== (ready.gtm.container_id ?? '');

  async function save(change: { indexnow?: { enabled: boolean };
    gtm?: { container_id?: string | null; enabled?: boolean } }, success: string) {
    if (!canUpdate || busy || ready === null) return;
    const attempt = requestId.current;
    setBusy(true);
    setNotice(null);
    try {
      const saved = await requestSettings(branchId, csrfToken, change);
      if (attempt !== requestId.current) return;
      setState({ status: 'ready', data: saved });
      setGtmInput(saved.gtm.container_id ?? '');
      setNotice(success);
    } catch (error) {
      if (attempt !== requestId.current) return;
      setNotice(error instanceof Error ? error.message
        : 'No fue posible guardar los cambios.');
    } finally {
      setBusy(false);
    }
  }

  function configureGtm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!validGtm || !gtmChanged) {
      setNotice('El identificador debe seguir el formato GTM-XXXXXXX.');
      return;
    }
    // El backend exige configuración y activación en solicitudes independientes.
    void save({ gtm: { container_id: normalizedGtm || null } },
      'Identificador GTM guardado. La activación permanece deshabilitada.');
  }

  if (!canView) return null;

  return (
    <section id="seo-settings" aria-labelledby="seo-settings-title">
      <h2 id="seo-settings-title">Configuración SEO · IndexNow y GTM</h2>
      <p className="muted">
        Ajustes exclusivos de la sede activa. Configurar no publica scripts
        ni envía notificaciones a proveedores externos.
      </p>
      <div aria-live="polite">
        {state.status === 'loading' && <p role="status">Cargando ajustes SEO…</p>}
        {state.status === 'error' && <p role="alert">{state.message}</p>}
        {notice !== null && <p role="status">{notice}</p>}
      </div>
      {state.status === 'error' && (
        <button type="button" onClick={() => { void load(); }}>Reintentar</button>
      )}
      {ready !== null && (
        <>
          <div>
            <h3>IndexNow</h3>
            <p>Estado de configuración: <strong>
              {ready.indexnow.enabled ? 'Activado' : 'Desactivado'}
            </strong></p>
            {canUpdate && (
              <button type="button" disabled={busy}
                onClick={() => { void save(
                  { indexnow: { enabled: !ready.indexnow.enabled } },
                  ready.indexnow.enabled ? 'IndexNow desactivado.' : 'IndexNow configurado como activo.',
                ); }}>
                {ready.indexnow.enabled ? 'Desactivar IndexNow' : 'Activar IndexNow'}
              </button>
            )}
            <h4>Últimas notificaciones</h4>
            {ready.indexnow.last_notifications.length === 0 ? (
              <p>No hay notificaciones IndexNow registradas.</p>
            ) : (
              <ul>{ready.indexnow.last_notifications.map((entry, i) => (
                <li key={i}>
                  {entry.created_at ?? 'Fecha no informada'} ·{' '}
                  {entry.status ?? 'Estado no informado'}
                </li>
              ))}</ul>
            )}
          </div>
          <div>
            <h3>Google Tag Manager</h3>
            <p>Estado de configuración: <strong>
              {ready.gtm.enabled ? 'Activado' : 'Desactivado'}
            </strong></p>
            <form onSubmit={configureGtm}>
              <label htmlFor="seo-gtm-id">Identificador GTM del contenedor</label>
              <input id="seo-gtm-id" value={gtmInput}
                placeholder="GTM-XXXXXXX" maxLength={64}
                disabled={!canUpdate || busy}
                aria-invalid={!validGtm}
                aria-describedby="seo-gtm-hint"
                onChange={(event) => setGtmInput(event.target.value)} />
              <p id="seo-gtm-hint" className="muted">
                Usa el formato GTM-XXXXXXX. Cambiar el identificador desactiva GTM
                hasta que lo vuelvas a habilitar por separado.
              </p>
              {!validGtm && <p role="alert">Identificador GTM inválido.</p>}
              {canUpdate && (
                <button type="submit" disabled={busy || !validGtm || !gtmChanged}>
                  Guardar identificador GTM
                </button>
              )}
            </form>
            {canUpdate && (
              <button type="button" disabled={busy || ready.gtm.container_id === null || gtmChanged}
                onClick={() => { void save({ gtm: { enabled: !ready.gtm.enabled } },
                  ready.gtm.enabled ? 'GTM desactivado.' : 'GTM configurado como activo.'); }}>
                {ready.gtm.enabled ? 'Desactivar GTM' : 'Activar GTM'}
              </button>
            )}
            <p className="muted">
              El renderizado público de GTM sigue condicionado a consentimiento,
              contexto público autorizado y políticas CSP verificadas.
            </p>
          </div>
        </>
      )}
    </section>
  );
}
